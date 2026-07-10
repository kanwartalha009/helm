<?php

declare(strict_types=1);

namespace App\Reports\AdsAudit;

use App\Models\AdCampaignDailyMetric;
use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Models\PlatformConnection;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
use App\Reports\Creative\CreativeReport;
use App\Reports\Support\AdAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Platform-scoped, shareable ads audit — the deep-dive companion to the
 * overall-performance report's audit section. One block per ad platform that
 * has campaign rows in the window: account KPIs with comparisons, the full
 * AdAudit rules result (verdicts, waste, action plan, confidence tags), and
 * the biggest budget movers ranked by absolute spend shift.
 *
 * Everything is computed from ad_campaign_daily_metrics ONLY (this is a
 * campaign-level report — no Shopify blend here). Money displays native (× the
 * stored fx snapshot in USD mode); ROAS is always the USD ratio so it's
 * currency-correct in either mode. Missing ≠ zero: an unconnected platform —
 * or a ?platform= filter naming one — yields no block, never €0 rows. The
 * freshness gate FAILS CLOSED on any error.
 */
final class AdsAuditReport implements ReportType
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /** Platforms with a creative-grain sync (mirrors CreativeReport). Google has
     * no ad_creative_daily rows, so its block carries no `creatives` key. */
    private const CREATIVE_PLATFORMS = ['meta', 'tiktok'];

    // Section caps — payload guards, not analytics thresholds.
    private const BEST_WORST_LIMIT    = 5;
    private const SEGMENT_ROWS_LIMIT  = 10;
    private const CREATIVE_LIST_LIMIT = 6;
    private const DETAIL_CAMPAIGNS    = 12;
    private const MAX_ISSUES          = 6;

    /** Canonical axis order for the segments section (meta_breakdown_daily). */
    private const SEGMENT_AXES = ['audience', 'age_gender', 'country', 'device', 'placement'];

    // CTR floor: published practitioner kill-rule floor of 0.5% CTR once an ad
    // has real delivery (topgrowthmarketing.com/facebook-automated-rules,
    // admanage.ai/blog/when-to-kill-a-facebook-ad).
    private const CTR_FLOOR_PCT       = 0.5;
    private const CTR_MIN_IMPRESSIONS = 1000;

    // [HELM DEFAULT threshold on a Google-native metric]: flag when ≥10% of
    // eligible Search/Shopping impressions are lost purely to budget cap
    // (AVG(search_budget_lost_is), a 0–1 fraction from the Google pull).
    private const BUDGET_LOST_IS_FLOOR = 0.10;

    public function __construct(private readonly AdAudit $ads) {}

    public function key(): string
    {
        return 'ads-audit';
    }

    public function label(): string
    {
        return 'Ads audit';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz              = $brand->timezone ?: 'UTC';
        [$start, $end]   = $filters->window($tz);
        [$cStart, $cEnd] = $filters->comparisonWindow($tz);

        /** @var array<int, string> $connected */
        $connected = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        // The platform scope: the requested platform when connected, otherwise
        // every connected ad platform. A filter naming an UNCONNECTED platform
        // scopes to nothing (empty list, hasData false) — never an error and
        // never fabricated rows.
        $selected = $filters->platform !== null
            ? array_values(array_intersect([$filters->platform], $connected))
            : array_values(array_intersect(self::AD_PLATFORMS, $connected));

        // Fault-isolated per platform: a broken block logs and drops out rather
        // than 500-ing the whole report. Platforms without rows return null.
        $platforms = [];
        foreach ($selected as $platform) {
            $block = $this->safely("platform.{$platform}", fn () => $this->platformBlock(
                $brand->id, $platform, $start, $end, $cStart, $cEnd, $filters->usd,
            ), null);
            if ($block !== null) {
                $platforms[] = $block;
            }
        }

        $currency = $filters->usd ? 'USD' : ($brand->base_currency ?: 'USD');

        return [
            'reportType' => $this->key(),
            'brand' => [
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $brand->timezone,
            ],
            'currency'   => $currency,
            'period'     => ['label' => $filters->periodLabel(), 'start' => $start, 'end' => $end],
            'comparison' => $cStart !== null
                ? ['label' => $filters->comparisonLabel(), 'start' => $cStart, 'end' => $cEnd]
                : null,
            'platformFilter' => $filters->platform, // null = all connected
            'platforms'      => $platforms,
            'hasData'        => $platforms !== [],
            // Is the campaign data current through the window end? Gated on the
            // data this report actually renders (ad_campaign_daily_metrics for
            // the selected platforms). On ANY error the gate FAILS CLOSED
            // (upToDate false + note) — a freshness bug must never un-gate a
            // stale report a client could receive.
            'freshness' => $this->safely('freshness', fn () => $this->freshness($brand->id, $selected, $end), [
                'upToDate' => false, 'lastSynced' => null, 'staleDays' => 0, 'windowEnd' => $end,
                'note'     => 'Freshness could not be verified — the report is held back until a sync confirms the data is current.',
            ]),
        ];
    }

    /**
     * Run an optional report section in isolation: on any failure, log it and
     * fall back to $default so one broken section never 500s the report.
     *
     * @param \Closure(): mixed $fn
     */
    private function safely(string $section, \Closure $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::warning('ads_audit_report.section_failed', [
                'section' => $section,
                'error'   => $e->getMessage(),
                'at'      => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $default;
        }
    }

    /**
     * One platform's block — account KPIs, the AdAudit rules result, and the
     * budget movers. Null when the platform has no campaign rows in the window
     * (the report omits it — missing ≠ zero).
     *
     * @return array<string, mixed>|null
     */
    private function platformBlock(int $brandId, string $platform, string $start, string $end, ?string $cStart, ?string $cEnd, bool $usd): ?array
    {
        $cur = $this->aggregate($brandId, $platform, $start, $end);
        if ($cur === []) {
            return null;
        }
        $prev = ($cStart !== null && $cEnd !== null) ? $this->aggregate($brandId, $platform, $cStart, $cEnd) : [];

        // One AdAudit run with an unbounded campaign list: the full set feeds
        // the movers' verdict/confidence lookup (rules stay owned by AdAudit —
        // never re-derived here), then the embedded audit is re-sliced to the
        // usual 12 rows. Never null here — $cur proved rows exist.
        $full = $this->ads->forPlatform($brandId, $platform, $start, $end, $cStart, $cEnd, $usd, limit: PHP_INT_MAX);
        if ($full === null) {
            return null;
        }
        $verdictById = [];
        foreach ($full['campaigns'] as $c) {
            $verdictById[$c['id']] = $c;
        }
        $audit              = $full;
        $audit['campaigns'] = array_slice($full['campaigns'], 0, 12);

        // Creative-grain rows (window vs prior window, one aggregate query each)
        // feed BOTH the `creatives` section and the per-campaign issue counts in
        // `campaignDetails`. Google has no creative sync — stays null and the
        // block carries no `creatives` key.
        $creativeRows = in_array($platform, self::CREATIVE_PLATFORMS, true)
            ? $this->safely("creative_rows.{$platform}", fn () => $this->creativeRows($brandId, $platform, $start, $end, $cStart, $cEnd), [])
            : null;

        [$best, $worst] = $this->bestWorst($cur, $usd);

        $block = [
            'platform' => $platform,
            'kpis'     => $this->kpis($cur, $prev !== [] ? $prev : null, $usd),
            'audit'    => $audit,
            'movers'   => $this->movers($cur, $prev, $verdictById, $usd),
            'best'     => $best,
            'worst'    => $worst,
            'segments' => $this->safely("segments.{$platform}", fn () => $this->segments($brandId, $platform, $start, $end, $usd), ['axes' => []]),
            'campaignDetails' => $this->safely(
                "campaign_details.{$platform}",
                fn () => $this->campaignDetails($brandId, $platform, $start, $end, $cur, $prev, $verdictById, $creativeRows, $usd),
                [],
            ),
        ];
        if ($creativeRows !== null) {
            $block['creatives'] = $this->creativesBlock($creativeRows, $usd);
        }

        return $block;
    }

    /**
     * Account KPIs from the campaign aggregates. Spend / conversion value / CPA
     * display in the report currency; ROAS is the USD ratio (deltaAbs, not %).
     * `previous` is null-safe: null when compare=none or the platform had no
     * rows in the comparison window (missing ≠ zero).
     *
     * @param array<string, array<string, mixed>> $cur
     * @param array<string, array<string, mixed>>|null $prev
     * @return array<string, mixed>
     */
    private function kpis(array $cur, ?array $prev, bool $usd): array
    {
        $t  = $this->totals($cur, $usd);
        $p  = $prev !== null ? $this->totals($prev, $usd) : null;

        $roas     = $t['spend_usd'] > 0 ? round($t['value_usd'] / $t['spend_usd'], 2) : null;
        $prevRoas = $p !== null && $p['spend_usd'] > 0 ? round($p['value_usd'] / $p['spend_usd'], 2) : null;

        $cpa     = $t['conversions'] > 0 ? round($t['spend'] / $t['conversions'], 2) : null;
        $prevCpa = $p !== null && $p['conversions'] > 0 ? round($p['spend'] / $p['conversions'], 2) : null;

        return [
            'spend' => [
                'value'    => round($t['spend'], 2),
                'previous' => $p !== null ? round($p['spend'], 2) : null,
                'deltaPct' => $this->pct($t['spend'], $p['spend'] ?? null),
            ],
            'conversionValue' => [
                'value'    => round($t['value'], 2),
                'previous' => $p !== null ? round($p['value'], 2) : null,
                'deltaPct' => $this->pct($t['value'], $p['value'] ?? null),
            ],
            'roas' => [
                'value'    => $roas,
                'previous' => $prevRoas,
                'deltaAbs' => ($roas !== null && $prevRoas !== null) ? round($roas - $prevRoas, 2) : null,
            ],
            'purchases' => [
                'value'    => $t['conversions'],
                'previous' => $p['conversions'] ?? null,
                'deltaPct' => $this->pct($t['conversions'], $p['conversions'] ?? null),
            ],
            'cpa' => [
                'value'    => $cpa,
                'previous' => $prevCpa,
                'deltaPct' => $this->pct($cpa, $prevCpa),
            ],
        ];
    }

    /**
     * Up to 8 campaigns ranked by the absolute USD spend shift vs the
     * comparison window — where the budget actually moved. Verdict + confidence
     * come from the AdAudit rules result (never re-derived here).
     *
     * @param array<string, array<string, mixed>> $cur
     * @param array<string, array<string, mixed>> $prev
     * @param array<string, array<string, mixed>> $verdictById
     * @return array<int, array<string, mixed>>
     */
    private function movers(array $cur, array $prev, array $verdictById, bool $usd, int $limit = 8): array
    {
        $rows = [];
        foreach ($cur as $c) {
            $p = $prev[$c['campaign_id']] ?? null;
            $rows[] = [
                'c'     => $c,
                'p'     => $p,
                'shift' => abs($c['spend_usd'] - ($p['spend_usd'] ?? 0.0)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['shift'] <=> $a['shift']);
        $rows = array_slice($rows, 0, $limit);

        $out = [];
        foreach ($rows as $r) {
            $c = $r['c'];
            $p = $r['p'];
            $v = $verdictById[$c['campaign_id']] ?? null;
            $roas     = $c['spend_usd'] > 0 ? round($c['value_usd'] / $c['spend_usd'], 2) : null;
            $prevRoas = $p !== null && $p['spend_usd'] > 0 ? round($p['value_usd'] / $p['spend_usd'], 2) : null;

            $out[] = [
                'campaignId'    => $c['campaign_id'],
                'name'          => $c['name'] !== '' ? $c['name'] : $c['campaign_id'],
                'spend'         => round($usd ? $c['spend_usd'] : $c['spend_native'], 2),
                'prevSpend'     => $p !== null ? round($usd ? $p['spend_usd'] : $p['spend_native'], 2) : null,
                'spendDeltaPct' => $this->pct($c['spend_usd'], $p['spend_usd'] ?? null),
                'roas'          => $roas,
                'prevRoas'      => $prevRoas,
                'verdict'       => $v['verdict'] ?? null,
                'confidence'    => $v['confidence'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array{spend: float, spend_usd: float, value: float, value_usd: float, conversions: int}
     */
    private function totals(array $rows, bool $usd): array
    {
        $spendNative = 0.0;
        $spendUsd    = 0.0;
        $valueNative = 0.0;
        $valueUsd    = 0.0;
        $conv        = 0;
        foreach ($rows as $r) {
            $spendNative += $r['spend_native'];
            $spendUsd    += $r['spend_usd'];
            $valueNative += $r['value_native'];
            $valueUsd    += $r['value_usd'];
            $conv        += $r['conversions'];
        }

        return [
            'spend'       => $usd ? $spendUsd : $spendNative,
            'spend_usd'   => $spendUsd,
            'value'       => $usd ? $valueUsd : $valueNative,
            'value_usd'   => $valueUsd,
            'conversions' => $conv,
        ];
    }

    /**
     * @return array<string, array<string, mixed>> per-campaign aggregates keyed by campaign_id
     */
    private function aggregate(int $brandId, string $platform, string $start, string $end): array
    {
        $rows = AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('campaign_id')
            ->selectRaw('campaign_id,
                MAX(campaign_name) AS name,
                MAX(status) AS status,
                MAX(channel_type) AS channel_type,
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value) AS value_native,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(conversions) AS conversions,
                AVG(search_budget_lost_is) AS budget_lost_is')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->campaign_id] = [
                'campaign_id'  => (string) $r->campaign_id,
                'name'         => (string) ($r->name ?? ''),
                'status'       => $r->status !== null ? (string) $r->status : null,
                'channel_type' => $r->channel_type !== null ? (string) $r->channel_type : null,
                'spend_native' => (float) $r->spend_native,
                'spend_usd'    => (float) $r->spend_usd,
                'value_native' => (float) $r->value_native,
                'value_usd'    => (float) $r->value_usd,
                'impressions'  => (int) $r->impressions,
                'clicks'       => (int) $r->clicks,
                'conversions'  => (int) $r->conversions,
                // AVG over the days that carry the metric; SQL AVG ignores NULLs
                // and stays NULL when no day has it (missing ≠ zero).
                'budget_lost_is' => $r->budget_lost_is !== null ? (float) $r->budget_lost_is : null,
            ];
        }

        return $out;
    }

    /**
     * Campaign ROAS for the NEW sections (best/worst, campaignDetails, series).
     * Missing ≠ zero: a campaign that spent but has NO attributed revenue and NO
     * attributed purchases gets null, never 0.0 — "the pixel isn't firing or it
     * truly hasn't sold" is a tracking question, not a 0× ratio.
     */
    private function roasOf(float $spendUsd, float $valueUsd, int $conversions): ?float
    {
        if ($spendUsd <= 0.0) {
            return null;
        }
        if ($valueUsd == 0.0 && $conversions === 0) {
            return null; // spend with zero attribution — unknown, not 0×
        }

        return round($valueUsd / $spendUsd, 2);
    }

    /**
     * Best / worst campaigns of the window (≤5 each) among campaigns that spent
     * ≥ AdAudit::MIN_SPEND USD (same verdict floor as the audit — sub-$50
     * campaigns aren't worth ranking). Best = ROAS desc, null-ROAS excluded.
     * Worst = null-ROAS spenders FIRST (spending with zero attributed revenue is
     * the worst possible outcome), biggest spender first, then ROAS asc; anything
     * already in `best` is excluded so tiny accounts don't mirror the two lists.
     *
     * @param array<string, array<string, mixed>> $cur
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function bestWorst(array $cur, bool $usd): array
    {
        $eligible = array_values(array_filter(
            $cur,
            static fn (array $c): bool => $c['spend_usd'] >= AdAudit::MIN_SPEND, // verdict spend floor (USD)
        ));

        $withRoas = [];
        $nullRoas = [];
        foreach ($eligible as $c) {
            $roas = $this->roasOf($c['spend_usd'], $c['value_usd'], $c['conversions']);
            if ($roas !== null) {
                $withRoas[] = ['c' => $c, 'roas' => $roas];
            } else {
                $nullRoas[] = ['c' => $c, 'roas' => null];
            }
        }

        usort($withRoas, static fn (array $a, array $b): int => $b['roas'] <=> $a['roas']);
        $best    = array_slice($withRoas, 0, self::BEST_WORST_LIMIT);
        $bestIds = array_flip(array_map(static fn (array $r): string => $r['c']['campaign_id'], $best));

        // Null-ROAS spenders first (biggest zero-return spend = worst), then the
        // remaining ranked campaigns by ROAS ascending.
        usort($nullRoas, static fn (array $a, array $b): int => $b['c']['spend_usd'] <=> $a['c']['spend_usd']);
        $rest = array_values(array_filter(
            $withRoas,
            static fn (array $r): bool => ! isset($bestIds[$r['c']['campaign_id']]),
        ));
        usort($rest, static fn (array $a, array $b): int => $a['roas'] <=> $b['roas']);
        $worst = array_slice(array_merge($nullRoas, $rest), 0, self::BEST_WORST_LIMIT);

        $row = function (array $r) use ($usd): array {
            $c     = $r['c'];
            $spend = $usd ? $c['spend_usd'] : $c['spend_native'];

            return [
                'campaignId'      => $c['campaign_id'],
                'name'            => $c['name'] !== '' ? $c['name'] : $c['campaign_id'],
                'status'          => $c['status'],
                'spend'           => round($spend, 2),
                'conversionValue' => round($usd ? $c['value_usd'] : $c['value_native'], 2),
                'roas'            => $r['roas'],
                'cpa'             => $c['conversions'] > 0 ? round($spend / $c['conversions'], 2) : null,
                'ctr'             => $c['impressions'] > 0 ? round($c['clicks'] / $c['impressions'] * 100, 2) : null,
                'cpm'             => $c['impressions'] > 0 ? round($spend / $c['impressions'] * 1000, 2) : null,
                'purchases'       => $c['conversions'],
                // Evidence tag — same SOLID_SPEND rule as AdAudit's verdicts.
                'confidence'      => $c['spend_usd'] < AdAudit::SOLID_SPEND ? 'early' : 'solid',
            ];
        };

        return [array_map($row, $best), array_map($row, $worst)];
    }

    /**
     * Customer segmentation from meta_breakdown_daily for this platform over the
     * window — ONE query grouped axis+segment, re-grouped per axis in PHP. Only
     * axes with rows appear (missing ≠ empty section); no rows at all → axes []
     * and the SPA shows its "no breakdown data synced" note.
     *
     * @return array{axes: array<int, array<string, mixed>>}
     */
    private function segments(int $brandId, string $platform, string $start, string $end, bool $usd): array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('breakdown_type', 'segment_key')
            ->selectRaw('breakdown_type,
                segment_key,
                MAX(segment_label) AS label,
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(conversions) AS conversions')
            ->get();

        /** @var array<string, array<int, array<string, mixed>>> $byAxis */
        $byAxis = [];
        foreach ($rows as $r) {
            $byAxis[(string) $r->breakdown_type][] = [
                'key'         => (string) $r->segment_key,
                'label'       => $r->label !== null ? (string) $r->label : null,
                'spend_native' => (float) $r->spend_native,
                'spend_usd'   => (float) $r->spend_usd,
                'value_usd'   => (float) $r->value_usd,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
                'conversions' => (int) $r->conversions,
            ];
        }

        $axes = [];
        foreach (self::SEGMENT_AXES as $axis) {
            if (! isset($byAxis[$axis])) {
                continue; // axis never synced in this window — absent, not empty
            }
            $segs = $byAxis[$axis];
            // Share of the WHOLE axis (USD, currency-invariant), computed before
            // the top-10 slice so the shares stay honest.
            $axisTotalUsd = array_sum(array_column($segs, 'spend_usd'));
            usort($segs, static fn (array $a, array $b): int => $b['spend_usd'] <=> $a['spend_usd']);
            $segs = array_slice($segs, 0, self::SEGMENT_ROWS_LIMIT);

            $out = [];
            foreach ($segs as $s) {
                $spend = $usd ? $s['spend_usd'] : $s['spend_native'];
                $out[] = [
                    'key'      => $s['key'],
                    'label'    => $s['label'],
                    'spend'    => round($spend, 2),
                    'sharePct' => $axisTotalUsd > 0.0 ? round($s['spend_usd'] / $axisTotalUsd * 100, 1) : null,
                    'ctr'      => $s['impressions'] > 0 ? round($s['clicks'] / $s['impressions'] * 100, 2) : null,
                    'cpm'      => $s['impressions'] > 0 ? round($spend / $s['impressions'] * 1000, 2) : null,
                    // USD ratio; null when the segment didn't spend or carries no
                    // conversions data at all (missing ≠ 0×).
                    'roas'      => $this->roasOf($s['spend_usd'], $s['value_usd'], $s['conversions']),
                    'purchases' => $s['conversions'],
                ];
            }
            $axes[] = ['axis' => $axis, 'rows' => $out];
        }

        return ['axes' => $axes];
    }

    /**
     * Per-creative window rows for meta/tiktok — ONE aggregate query per window
     * (current + prior), each row pre-computing the flags both consumers need:
     * the winner/fatigue rules for the `creatives` section and the per-campaign
     * counts for `campaignDetails` issues.
     *
     * @return array<int, array<string, mixed>>
     */
    private function creativeRows(int $brandId, string $platform, string $start, string $end, ?string $cStart, ?string $cEnd): array
    {
        $cur = $this->creativeAggregate($brandId, $platform, $start, $end);
        if ($cur === []) {
            return [];
        }
        $prev = ($cStart !== null && $cEnd !== null) ? $this->creativeAggregate($brandId, $platform, $cStart, $cEnd) : [];

        $rows = [];
        foreach ($cur as $c) {
            $p = $prev[$c['ad_id']] ?? null;
            // CreativeReport's ROAS convention at the creative grain (spend > 0 →
            // numeric ratio) so the median/threshold math below is identical.
            $roas     = $c['spend_usd'] > 0 ? round($c['value_usd'] / $c['spend_usd'], 2) : null;
            $prevRoas = $p !== null && $p['spend_usd'] > 0 ? round($p['value_usd'] / $p['spend_usd'], 2) : null;
            $ctr      = $c['impressions'] > 0 ? round($c['clicks'] / $c['impressions'] * 100, 2) : null;
            $prevCtr  = $p !== null && $p['impressions'] > 0 ? round($p['clicks'] / $p['impressions'] * 100, 2) : null;

            // Fatigue rule — CreativeReport::FATIGUE_MIN_SPEND / FATIGUE_DROP_PCT
            // verbatim: $100 USD floor, must have run in the prior window, and
            // ROAS or CTR fell ≥30% vs that window.
            $roasDrop = $this->dropPct($roas, $prevRoas);
            $ctrDrop  = $this->dropPct($ctr, $prevCtr);
            $fatigued = $c['spend_usd'] >= CreativeReport::FATIGUE_MIN_SPEND
                && ($p['spend_usd'] ?? 0.0) > 0.0
                && (($roasDrop !== null && $roasDrop >= CreativeReport::FATIGUE_DROP_PCT)
                    || ($ctrDrop !== null && $ctrDrop >= CreativeReport::FATIGUE_DROP_PCT));

            $rows[] = $c + [
                'roas'      => $roas,
                'prev_roas' => $prevRoas,
                'ctr'       => $ctr,
                'prev_ctr'  => $prevCtr,
                'fatigued'  => $fatigued,
            ];
        }

        return $rows;
    }

    /** Percentage DROP prev→cur (positive = fell) — CreativeReport::dropPct verbatim. */
    private function dropPct(?float $cur, ?float $prev): ?float
    {
        if ($prev === null || $prev <= 0.0) {
            return null;
        }

        return round(($prev - ($cur ?? 0.0)) / $prev * 100, 1);
    }

    /**
     * The `creatives` section (meta/tiktok blocks only): winners + fatigued from
     * the pre-computed creative rows. Winner rule — CreativeReport::SCALE_MIN_SPEND
     * / SCALE_ROAS_MULT verbatim: spend ≥ $50 USD AND ROAS ≥ 2.0 × the platform
     * median creative ROAS (median across creatives that spent this window, same
     * basis as CreativeReport::scaleCandidates).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{winners: array<int, array<string, mixed>>, fatigued: array<int, array<string, mixed>>, status: string}
     */
    private function creativesBlock(array $rows, bool $usd): array
    {
        if ($rows === []) {
            return ['winners' => [], 'fatigued' => [], 'status' => 'no_data'];
        }

        $roasValues = [];
        foreach ($rows as $r) {
            if ($r['spend_usd'] > 0.0 && $r['roas'] !== null) {
                $roasValues[] = (float) $r['roas'];
            }
        }
        $median = $this->median($roasValues);

        $winners = [];
        if ($median !== null && $median > 0.0) {
            $winners = array_values(array_filter($rows, static fn (array $r): bool => $r['spend_usd'] >= CreativeReport::SCALE_MIN_SPEND
                && $r['roas'] !== null
                && (float) $r['roas'] >= CreativeReport::SCALE_ROAS_MULT * $median));
            usort($winners, static fn (array $a, array $b): int => $b['roas'] <=> $a['roas']);
        }

        $fatigued = array_values(array_filter($rows, static fn (array $r): bool => $r['fatigued']));
        usort($fatigued, static fn (array $a, array $b): int => $b['spend_usd'] <=> $a['spend_usd']);

        $row = static function (array $r) use ($usd): array {
            $spend = $usd ? $r['spend_usd'] : $r['spend_native'];

            return [
                'adId'         => $r['ad_id'],
                'name'         => $r['name'] !== '' ? $r['name'] : $r['ad_id'],
                'thumbnailUrl' => $r['thumbnail_url'],
                'mediaType'    => $r['media_type'],
                'spend'        => round($spend, 2),
                'roas'         => $r['roas'],
                'ctr'          => $r['ctr'],
                'thumbstopPct' => $r['impressions'] > 0 ? round($r['video_3s'] / $r['impressions'] * 100, 1) : null,
                'holdPct'      => $r['video_3s'] > 0 ? round($r['thruplays'] / $r['video_3s'] * 100, 1) : null,
                'cpa'          => $r['conversions'] > 0 ? round($spend / $r['conversions'], 2) : null,
                'belowAverage' => $r['below_average'],
            ];
        };

        return [
            'winners'  => array_map($row, array_slice($winners, 0, self::CREATIVE_LIST_LIMIT)),
            'fatigued' => array_map($row, array_slice($fatigued, 0, self::CREATIVE_LIST_LIMIT)),
            'status'   => 'ok',
        ];
    }

    /** @param array<int, float> $values — CreativeReport::median verbatim. */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * @return array<string, array<string, mixed>> per-ad window aggregates keyed by ad_id
     */
    private function creativeAggregate(int $brandId, string $platform, string $start, string $end): array
    {
        $rows = AdCreativeDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('ad_id')
            ->selectRaw("ad_id,
                MAX(ad_name) AS name,
                MAX(campaign_id) AS campaign_id,
                MAX(thumbnail_url) AS thumbnail_url,
                MAX(media_type) AS media_type,
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(conversions) AS conversions,
                SUM(video_3s) AS video_3s,
                SUM(thruplays) AS thruplays,
                MAX(CASE WHEN quality_ranking LIKE 'below_average%'
                          OR engagement_ranking LIKE 'below_average%'
                          OR conversion_ranking LIKE 'below_average%'
                     THEN 1 ELSE 0 END) AS below_average")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->ad_id] = [
                'ad_id'         => (string) $r->ad_id,
                'name'          => (string) ($r->name ?? ''),
                'campaign_id'   => $r->campaign_id !== null ? (string) $r->campaign_id : null,
                'thumbnail_url' => $r->thumbnail_url !== null ? (string) $r->thumbnail_url : null,
                'media_type'    => $r->media_type !== null ? (string) $r->media_type : null,
                'spend_native'  => (float) $r->spend_native,
                'spend_usd'     => (float) $r->spend_usd,
                'value_usd'     => (float) $r->value_usd,
                'impressions'   => (int) $r->impressions,
                'clicks'        => (int) $r->clicks,
                'conversions'   => (int) $r->conversions,
                'video_3s'      => (int) $r->video_3s,
                'thruplays'     => (int) $r->thruplays,
                // "Any of quality/engagement/conversion ranking starts with
                // 'below_average'" on any day in the window.
                'below_average' => (int) $r->below_average === 1,
            ];
        }

        return $out;
    }

    /**
     * The sidebar data: ≤12 campaigns by window spend desc, each with its KPIs,
     * a daily spend/ROAS series (ONE query across all detail campaigns — only
     * dates with rows), and a deterministic, ordered issue list (≤6 per
     * campaign). Verdict/confidence come from the AdAudit rules result.
     *
     * @param array<string, array<string, mixed>> $cur
     * @param array<string, array<string, mixed>> $prev
     * @param array<string, array<string, mixed>> $verdictById
     * @param array<int, array<string, mixed>>|null $creativeRows null = platform has no creative grain (google)
     * @return array<int, array<string, mixed>>
     */
    private function campaignDetails(
        int $brandId,
        string $platform,
        string $start,
        string $end,
        array $cur,
        array $prev,
        array $verdictById,
        ?array $creativeRows,
        bool $usd,
    ): array {
        $ranked = array_values($cur);
        usort($ranked, static fn (array $a, array $b): int => $b['spend_usd'] <=> $a['spend_usd']);
        $ranked = array_slice($ranked, 0, self::DETAIL_CAMPAIGNS);
        $ids    = array_map(static fn (array $c): string => $c['campaign_id'], $ranked);

        $seriesById = $this->seriesByCampaign($brandId, $platform, $start, $end, $ids, $usd);

        // Per-campaign creative counts (from the ONE creative aggregate already
        // in hand) for the below-average and fatigue issues.
        $belowAvgCount = [];
        $fatiguedCount = [];
        foreach ($creativeRows ?? [] as $r) {
            $cid = $r['campaign_id'];
            if ($cid === null) {
                continue;
            }
            // Spend floor: AdAudit::MIN_SPEND — a sub-$50 creative isn't evidence.
            if ($r['below_average'] && $r['spend_usd'] >= AdAudit::MIN_SPEND) {
                $belowAvgCount[$cid] = ($belowAvgCount[$cid] ?? 0) + 1;
            }
            if ($r['fatigued']) {
                $fatiguedCount[$cid] = ($fatiguedCount[$cid] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($ranked as $c) {
            $cid   = $c['campaign_id'];
            $p     = $prev[$cid] ?? null;
            $v     = $verdictById[$cid] ?? null;
            $spend = $usd ? $c['spend_usd'] : $c['spend_native'];
            $roas  = $this->roasOf($c['spend_usd'], $c['value_usd'], $c['conversions']);
            $spendDeltaPct = $this->pct($c['spend_usd'], $p['spend_usd'] ?? null);

            $out[] = [
                'campaignId'  => $cid,
                'name'        => $c['name'] !== '' ? $c['name'] : $cid,
                'status'      => $c['status'],
                'channelType' => $c['channel_type'],
                'verdict'     => $v['verdict'] ?? null,
                'confidence'  => $v['confidence'] ?? null,
                'kpis'        => [
                    'spend'     => round($spend, 2),
                    'prevSpend' => $p !== null ? round($usd ? $p['spend_usd'] : $p['spend_native'], 2) : null,
                    'roas'      => $roas,
                    'prevRoas'  => $p !== null ? $this->roasOf($p['spend_usd'], $p['value_usd'], $p['conversions']) : null,
                    'cpa'       => $c['conversions'] > 0 ? round($spend / $c['conversions'], 2) : null,
                    'ctr'       => $c['impressions'] > 0 ? round($c['clicks'] / $c['impressions'] * 100, 2) : null,
                    'cpm'       => $c['impressions'] > 0 ? round($spend / $c['impressions'] * 1000, 2) : null,
                    'purchases' => $c['conversions'],
                ],
                'series' => $seriesById[$cid] ?? [],
                'issues' => $this->campaignIssues($platform, $c, $roas, $spendDeltaPct, $spend, $belowAvgCount[$cid] ?? 0, $fatiguedCount[$cid] ?? 0, $creativeRows !== null),
            ];
        }

        return $out;
    }

    /**
     * Daily spend/ROAS series for the detail campaigns — ONE query for all ids,
     * grouped date+campaign; only dates with rows appear (missing days are
     * absent, never 0-filled).
     *
     * @param array<int, string> $ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seriesByCampaign(int $brandId, string $platform, string $start, string $end, array $ids, bool $usd): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->whereIn('campaign_id', $ids)
            ->groupBy('date', 'campaign_id')
            ->selectRaw('date, campaign_id,
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd,
                SUM(conversions) AS conversions')
            ->orderBy('date')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $spendUsd = (float) $r->spend_usd;
            $out[(string) $r->campaign_id][] = [
                'date'  => CarbonImmutable::parse((string) $r->date)->toDateString(),
                'spend' => round($usd ? $spendUsd : (float) $r->spend_native, 2),
                'roas'  => $this->roasOf($spendUsd, (float) $r->value_usd, (int) $r->conversions),
            ];
        }

        return $out;
    }

    /**
     * Deterministic issue list for one campaign — every rule cites its threshold
     * source, evaluated in severity order, capped at MAX_ISSUES.
     *
     * @param array<string, mixed> $c window aggregate row
     * @return array<int, array{severity: string, title: string, detail: string}>
     */
    private function campaignIssues(
        string $platform,
        array $c,
        ?float $roas,
        ?float $spendDeltaPct,
        float $displaySpend,
        int $belowAvgCreatives,
        int $fatiguedCreatives,
        bool $hasCreativeGrain,
    ): array {
        $issues = [];

        // AdAudit::MIN_SPEND ($50 verdict floor) + null ROAS = real money out,
        // zero attribution back — the single most urgent thing to check.
        if ($c['spend_usd'] >= AdAudit::MIN_SPEND && $roas === null) {
            $issues[] = [
                'severity' => 'critical',
                'title'    => 'Spending with no attributed revenue',
                'detail'   => round($displaySpend, 2) . " spent with nothing attributed back — either the pixel isn't firing for this campaign or it truly hasn't sold — check tracking first",
            ];
        }

        // AdAudit::DEAD_ROAS (1.0×): below break-even on ad spend.
        if ($roas !== null && $roas < AdAudit::DEAD_ROAS) {
            $issues[] = [
                'severity' => 'critical',
                'title'    => 'Below 1× ROAS — every euro in returns less than a euro out',
                'detail'   => "Window ROAS {$roas}x on " . round($displaySpend, 2) . ' spend — pause or rebuild before putting more budget behind it',
            ];
        }

        // AdAudit::WEAK_ROAS (1.8×) + AdAudit::SCALING (+20% spend): growing the
        // budget on a campaign that isn't working yet compounds the loss.
        if ($roas !== null && $roas >= AdAudit::DEAD_ROAS && $roas < AdAudit::WEAK_ROAS) {
            if ($spendDeltaPct !== null && $spendDeltaPct > AdAudit::SCALING) {
                $issues[] = [
                    'severity' => 'critical',
                    'title'    => 'Scaling a loss — budget grew ' . round($spendDeltaPct, 1) . '% while ROAS sits under 1.8×',
                    'detail'   => "Spend is up " . round($spendDeltaPct, 1) . "% vs the prior period at {$roas}x ROAS — cap the budget until the ratio recovers",
                ];
            } else {
                // AdAudit::WEAK_ROAS (1.8×) without the scaling aggravator.
                $issues[] = [
                    'severity' => 'warn',
                    'title'    => 'Under the 1.8× working threshold',
                    'detail'   => "Window ROAS {$roas}x — covering its costs but not earning its budget; review targeting or refresh the creative",
                ];
            }
        }

        // CTR_FLOOR_PCT (0.5%) with CTR_MIN_IMPRESSIONS delivery — published
        // practitioner floor (topgrowthmarketing.com/facebook-automated-rules,
        // admanage.ai/blog/when-to-kill-a-facebook-ad).
        $ctr = $c['impressions'] > 0 ? $c['clicks'] / $c['impressions'] * 100 : null;
        if ($c['impressions'] >= self::CTR_MIN_IMPRESSIONS && $ctr !== null && $ctr < self::CTR_FLOOR_PCT) {
            $issues[] = [
                'severity' => 'warn',
                'title'    => 'CTR under the 0.5% floor — creative or audience mismatch',
                'detail'   => 'CTR ' . round($ctr, 2) . '% across ' . $c['impressions'] . ' impressions — the ad is being shown but not clicked',
            ];
        }

        // BUDGET_LOST_IS_FLOOR (10%) on Google's search_budget_lost_is —
        // [HELM DEFAULT threshold on a Google-native metric].
        if ($platform === 'google' && $c['budget_lost_is'] !== null && $c['budget_lost_is'] >= self::BUDGET_LOST_IS_FLOOR) {
            $lostPct = round($c['budget_lost_is'] * 100, 1);
            $issues[] = [
                'severity' => 'info',
                'title'    => "Losing {$lostPct}% of eligible impressions to budget cap",
                'detail'   => 'Google reports the campaign is budget-limited — if it performs, raising the cap buys impressions it already qualifies for',
            ];
        }

        // Creative-grain issues (meta/tiktok): counts from ad_creative_daily,
        // spend floor AdAudit::MIN_SPEND, below_average = Meta relevance ranking.
        if ($hasCreativeGrain && $belowAvgCreatives > 0) {
            $issues[] = [
                'severity' => 'warn',
                'title'    => "{$belowAvgCreatives} creative(s) ranked below average by the platform",
                'detail'   => 'Meta relevance diagnostics put them below average on quality, engagement or conversion — refresh before they drag delivery',
            ];
        }

        // Fatigue rule — CreativeReport::FATIGUE_MIN_SPEND / FATIGUE_DROP_PCT
        // ($100 floor, ROAS or CTR down ≥30% vs the prior window).
        if ($hasCreativeGrain && $fatiguedCreatives > 0) {
            $issues[] = [
                'severity' => 'warn',
                'title'    => "{$fatiguedCreatives} creative(s) fatiguing — ROAS/CTR down ≥30% vs the prior period",
                'detail'   => 'Performance is decaying on creatives that used to work — rotate in fresh variants',
            ];
        }

        // AdAudit::SOLID_SPEND ($150 evidence floor) — verdicts under it are
        // 'early'; say so in the sidebar too.
        if ($c['spend_usd'] < AdAudit::SOLID_SPEND) {
            $issues[] = [
                'severity' => 'info',
                'title'    => 'Early signal — under $150 spend in this window; verify before acting',
                'detail'   => 'Only ' . round($c['spend_usd'], 2) . ' USD of evidence — below the 150 USD confidence floor, so treat every read above as provisional',
            ];
        }

        return array_slice($issues, 0, self::MAX_ISSUES);
    }

    /**
     * Is the campaign data current through the window end? MAX(date) in
     * ad_campaign_daily_metrics for the brand + selected platforms vs the
     * window end. Callers wrap this in safely() with a FAIL-CLOSED default.
     *
     * @param array<int, string> $platforms
     * @return array<string, mixed>
     */
    private function freshness(int $brandId, array $platforms, string $windowEnd): array
    {
        $lastSynced = AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', $platforms)
            ->max('date');

        $end  = CarbonImmutable::parse($windowEnd)->startOfDay();
        $last = $lastSynced !== null ? CarbonImmutable::parse((string) $lastSynced)->startOfDay() : null;

        return [
            'upToDate'   => $last !== null && $last->greaterThanOrEqualTo($end),
            'lastSynced' => $last?->toDateString(),
            'staleDays'  => ($last !== null && $last->lessThan($end)) ? (int) $last->diffInDays($end) : 0,
            'windowEnd'  => $end->toDateString(),
        ];
    }

    private function pct(float|int|null $cur, float|int|null $prev): ?float
    {
        if ($cur === null || $prev === null || (float) $prev === 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $prev) / (float) $prev * 100, 1);
    }
}
