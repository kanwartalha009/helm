<?php

declare(strict_types=1);

namespace App\Reports\AdsAudit;

use App\Models\AdCampaignDailyMetric;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
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

        return [
            'platform' => $platform,
            'kpis'     => $this->kpis($cur, $prev !== [] ? $prev : null, $usd),
            'audit'    => $audit,
            'movers'   => $this->movers($cur, $prev, $verdictById, $usd),
        ];
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
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value) AS value_native,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd,
                SUM(conversions) AS conversions')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->campaign_id] = [
                'campaign_id'  => (string) $r->campaign_id,
                'name'         => (string) ($r->name ?? ''),
                'spend_native' => (float) $r->spend_native,
                'spend_usd'    => (float) $r->spend_usd,
                'value_native' => (float) $r->value_native,
                'value_usd'    => (float) $r->value_usd,
                'conversions'  => (int) $r->conversions,
            ];
        }

        return $out;
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
