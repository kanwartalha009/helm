<?php

declare(strict_types=1);

namespace App\Reports\Creative;

use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creative performance report (spec §2 weekly-ad / Meta-audit creative grain) —
 * which ads earned their budget. Built from ad_creative_daily per ad platform
 * that has creative rows in the window (Meta today, TikTok when its sync lands);
 * a platform without rows is simply absent, never an empty €0 block.
 *
 * Uses the shared ReportFilters period (last7 / last30 / mtd) and comparison
 * window for the period-over-period deltas that drive the fatigue rule. Every
 * flag is deterministic and rules-based — the LLM layer writes prose around
 * these, never the figures. ROAS and the spend floors are computed in USD so
 * the ratios and thresholds are currency-correct in either display mode.
 */
final class CreativeReport implements ReportType
{
    /** Platforms with a creative-grain sync. Only those with rows appear. */
    private const CREATIVE_PLATFORMS = ['meta', 'tiktok'];

    // Fatigue rule (deterministic): a creative is flagged fatigued when ALL of
    //   - spend this window ≥ FATIGUE_MIN_SPEND (USD floor — small tests aren't
    //     "fatigued", they're just small), AND
    //   - it also ran in the comparison window (prev spend > 0 — a brand-new
    //     creative has nothing to fall from), AND
    //   - ROAS fell ≥ FATIGUE_DROP_PCT vs the comparison window, or CTR did.
    private const FATIGUE_MIN_SPEND = 100.0;
    private const FATIGUE_DROP_PCT  = 30.0;

    // Scale rule (deterministic): ROAS ≥ SCALE_ROAS_MULT × the platform's median
    // creative ROAS this window, with meaningful spend (≥ SCALE_MIN_SPEND USD).
    private const SCALE_MIN_SPEND = 50.0;
    private const SCALE_ROAS_MULT = 2.0;

    public function key(): string
    {
        return 'creatives';
    }

    public function label(): string
    {
        return 'Creative performance';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz              = $brand->timezone ?: 'UTC';
        [$start, $end]   = $filters->window($tz);
        [$cStart, $cEnd] = $filters->comparisonWindow($tz);

        // Fault-isolated per platform: a broken block logs and drops out rather
        // than 500-ing the whole report. Platforms without rows return null.
        $platforms = [];
        foreach (self::CREATIVE_PLATFORMS as $platform) {
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
            'platforms'  => $platforms,
            // Same freshness contract as the other reports, but gated on the data
            // this report actually renders: the latest complete creative day on
            // file. No creative rows at all → lastSynced null and the SPA shows
            // its "no synced data" gate. On error, default to "up to date" so a
            // freshness glitch never blocks the view.
            'freshness' => $this->safely('freshness', fn () => $this->freshness($brand->id, $end), [
                'upToDate' => true, 'lastSynced' => null, 'staleDays' => 0, 'windowEnd' => $end,
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
            Log::warning('creative_report.section_failed', [
                'section' => $section,
                'error'   => $e->getMessage(),
                'at'      => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $default;
        }
    }

    /**
     * One platform's creative block — summary, top creatives, fatigue and scale
     * flags, media mix. Null when the platform has no creative rows in the
     * window (the report omits it — missing ≠ zero).
     *
     * @return array<string, mixed>|null
     */
    private function platformBlock(int $brandId, string $platform, string $start, string $end, ?string $cStart, ?string $cEnd, bool $usd): ?array
    {
        $cur = $this->aggregate($brandId, $platform, $start, $end);
        if ($cur === []) {
            return null;
        }
        $prev  = ($cStart !== null && $cEnd !== null) ? $this->aggregate($brandId, $platform, $cStart, $cEnd) : [];
        $ranks = $this->latestRankings($brandId, $platform, $start, $end);

        $disp = static fn (array $r, string $native, string $inUsd): float => $usd ? $r[$inUsd] : $r[$native];

        // Platform totals (ROAS in USD so the ratio is currency-correct).
        $totSpend    = 0.0;
        $totRevenue  = 0.0;
        $totSpendUsd = 0.0;
        $totValueUsd = 0.0;
        foreach ($cur as $c) {
            $totSpend    += $disp($c, 'spend_native', 'spend_usd');
            $totRevenue  += $disp($c, 'value_native', 'value_usd');
            $totSpendUsd += $c['spend_usd'];
            $totValueUsd += $c['value_usd'];
        }

        // Per-creative rows, ranked by spend (where the money went).
        usort($cur, static fn (array $a, array $b): int => $b['spend_usd'] <=> $a['spend_usd']);

        $rows = [];
        foreach ($cur as $c) {
            $p        = $prev[$c['ad_id']] ?? null;
            $spend    = round($disp($c, 'spend_native', 'spend_usd'), 2);
            $roas     = $c['spend_usd'] > 0 ? round($c['value_usd'] / $c['spend_usd'], 2) : null;
            $prevRoas = $p !== null && $p['spend_usd'] > 0 ? round($p['value_usd'] / $p['spend_usd'], 2) : null;
            $ctr      = $c['impressions'] > 0 ? round($c['clicks'] / $c['impressions'] * 100, 2) : null;
            $prevCtr  = $p !== null && $p['impressions'] > 0 ? round($p['clicks'] / $p['impressions'] * 100, 2) : null;
            $rank     = $ranks[$c['ad_id']] ?? ['quality' => null, 'engagement' => null, 'conversion' => null];

            $rows[] = [
                'id'         => $c['ad_id'],
                'name'       => $c['name'] !== '' ? $c['name'] : $c['ad_id'],
                'mediaType'  => $c['media_type'],
                'spend'      => $spend,
                'spendShare' => $totSpend > 0.0 ? round($spend / $totSpend, 4) : null,
                'revenue'    => round($disp($c, 'value_native', 'value_usd'), 2),
                'roas'       => $roas,
                'purchases'  => $c['conversions'],
                'cpa'        => $c['conversions'] > 0 ? round($spend / $c['conversions'], 2) : null,
                'ctr'        => $ctr,
                // Thumbstop = 3-sec video views ÷ impressions; hold = ThruPlays ÷
                // 3-sec views. Both null (never 0) for image creatives or when the
                // pull carried no video engagement.
                'thumbstop'  => ($c['video_3s'] > 0 && $c['impressions'] > 0) ? round($c['video_3s'] / $c['impressions'] * 100, 1) : null,
                'hold'       => $c['video_3s'] > 0 ? round($c['thruplays'] / $c['video_3s'] * 100, 1) : null,
                'addToCarts' => $c['add_to_cart'],
                'rankings'   => [
                    'quality'      => $rank['quality'],
                    'engagement'   => $rank['engagement'],
                    'conversion'   => $rank['conversion'],
                    'belowAverage' => $this->isBelowAverage($rank),
                ],
                'prevRoas'   => $prevRoas,
                'roasDelta'  => ($roas !== null && $prevRoas !== null) ? round($roas - $prevRoas, 2) : null,
                'spendDelta' => $this->pct($c['spend_usd'], $p['spend_usd'] ?? null),
                // Internal fields for the rules below — stripped before output.
                '_spend_usd' => $c['spend_usd'],
                '_prev_spend_usd' => $p['spend_usd'] ?? null,
                '_prev_ctr'  => $prevCtr,
            ];
        }

        $fatigued        = $this->fatigued($rows);
        $scaleCandidates = $this->scaleCandidates($rows);
        $mediaMix        = $this->mediaMix($rows, $totSpend);

        $public = array_map(static function (array $r): array {
            unset($r['_spend_usd'], $r['_prev_spend_usd'], $r['_prev_ctr']);

            return $r;
        }, $rows);

        return [
            'platform' => $platform,
            'summary'  => [
                'creatives' => count($cur),
                'spend'     => round($totSpend, 2),
                'revenue'   => round($totRevenue, 2),
                'roas'      => $totSpendUsd > 0.0 ? round($totValueUsd / $totSpendUsd, 2) : null,
            ],
            'topCreatives'    => array_slice($public, 0, 10),
            'totalCreatives'  => count($cur),
            'fatigued'        => $fatigued,
            'scaleCandidates' => $scaleCandidates,
            'mediaMix'        => $mediaMix,
        ];
    }

    /**
     * Fatigue flags — see the FATIGUE_* constants for the rule. Each flag names
     * which signal fell and by how much, so the card is honest and checkable.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function fatigued(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['_spend_usd'] < self::FATIGUE_MIN_SPEND) {
                continue; // below the spend floor — not meaningful
            }
            if (($r['_prev_spend_usd'] ?? 0.0) <= 0.0) {
                continue; // didn't run in the comparison window — nothing to fall from
            }

            $roasDrop = $this->dropPct($r['roas'], $r['prevRoas']);
            $ctrDrop  = $this->dropPct($r['ctr'], $r['_prev_ctr']);

            $reasons = [];
            if ($roasDrop !== null && $roasDrop >= self::FATIGUE_DROP_PCT) {
                $reasons[] = sprintf('ROAS fell %.0f%% (%.2fx to %.2fx)', $roasDrop, $r['prevRoas'], $r['roas'] ?? 0.0);
            }
            if ($ctrDrop !== null && $ctrDrop >= self::FATIGUE_DROP_PCT) {
                $reasons[] = sprintf('CTR fell %.0f%% (%.2f%% to %.2f%%)', $ctrDrop, $r['_prev_ctr'], $r['ctr'] ?? 0.0);
            }
            if ($reasons === []) {
                continue;
            }

            $out[] = [
                'id'        => $r['id'],
                'name'      => $r['name'],
                'mediaType' => $r['mediaType'],
                'spend'     => $r['spend'],
                'roas'      => $r['roas'],
                'prevRoas'  => $r['prevRoas'],
                'ctr'       => $r['ctr'],
                'prevCtr'   => $r['_prev_ctr'],
                'reason'    => implode(' · ', $reasons),
            ];
        }

        return $out;
    }

    /** Percentage DROP from prev to cur (positive = fell), or null when unknowable. */
    private function dropPct(?float $cur, ?float $prev): ?float
    {
        if ($prev === null || $prev <= 0.0) {
            return null;
        }

        return round(($prev - ($cur ?? 0.0)) / $prev * 100, 1);
    }

    /**
     * Scale candidates — see the SCALE_* constants for the rule. The platform
     * median is computed over creatives that actually spent this window.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function scaleCandidates(array $rows): array
    {
        $roasValues = [];
        foreach ($rows as $r) {
            if ($r['_spend_usd'] > 0.0 && $r['roas'] !== null) {
                $roasValues[] = (float) $r['roas'];
            }
        }
        $median = $this->median($roasValues);
        if ($median === null || $median <= 0.0) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            if ($r['_spend_usd'] < self::SCALE_MIN_SPEND || $r['roas'] === null) {
                continue;
            }
            if ((float) $r['roas'] < self::SCALE_ROAS_MULT * $median) {
                continue;
            }
            $out[] = [
                'id'             => $r['id'],
                'name'           => $r['name'],
                'mediaType'      => $r['mediaType'],
                'spend'          => $r['spend'],
                'spendShare'     => $r['spendShare'],
                'roas'           => $r['roas'],
                'platformMedian' => round($median, 2),
            ];
        }

        return $out;
    }

    /**
     * Spend by media type (image / video / unknown), share of platform spend.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mediaMix(array $rows, float $totSpend): array
    {
        $mix = [];
        foreach ($rows as $r) {
            $type = is_string($r['mediaType']) && $r['mediaType'] !== '' ? $r['mediaType'] : 'unknown';
            $mix[$type] ??= ['mediaType' => $type, 'spend' => 0.0, 'creatives' => 0];
            $mix[$type]['spend'] += (float) $r['spend'];
            $mix[$type]['creatives']++;
        }

        $out = array_values($mix);
        foreach ($out as &$m) {
            $m['spend'] = round($m['spend'], 2);
            $m['share'] = $totSpend > 0.0 ? round($m['spend'] / $totSpend, 4) : null;
        }
        unset($m);
        usort($out, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>> per-ad aggregates keyed by ad_id
     */
    private function aggregate(int $brandId, string $platform, string $start, string $end): array
    {
        $rows = AdCreativeDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('ad_id')
            ->selectRaw('ad_id,
                MAX(ad_name) AS name,
                MAX(media_type) AS media_type,
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value) AS value_native,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(conversions) AS conversions,
                SUM(video_3s) AS video_3s,
                SUM(thruplays) AS thruplays,
                SUM(add_to_cart) AS add_to_cart')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->ad_id] = [
                'ad_id'        => (string) $r->ad_id,
                'name'         => (string) ($r->name ?? ''),
                'media_type'   => $r->media_type !== null ? (string) $r->media_type : null,
                'spend_native' => (float) $r->spend_native,
                'spend_usd'    => (float) $r->spend_usd,
                'value_native' => (float) $r->value_native,
                'value_usd'    => (float) $r->value_usd,
                'impressions'  => (int) $r->impressions,
                'clicks'       => (int) $r->clicks,
                'conversions'  => (int) $r->conversions,
                'video_3s'     => (int) $r->video_3s,
                'thruplays'    => (int) $r->thruplays,
                'add_to_cart'  => (int) $r->add_to_cart,
            ];
        }

        return $out;
    }

    /**
     * Meta relevance rankings don't aggregate — take each ad's most recent
     * ranked day in the window (rows ordered ascending; the latest overwrites).
     *
     * @return array<string, array{quality: ?string, engagement: ?string, conversion: ?string}>
     */
    private function latestRankings(int $brandId, string $platform, string $start, string $end): array
    {
        $rows = AdCreativeDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->where(function ($q): void {
                $q->whereNotNull('quality_ranking')
                    ->orWhereNotNull('engagement_ranking')
                    ->orWhereNotNull('conversion_ranking');
            })
            ->orderBy('date')
            ->get(['ad_id', 'date', 'quality_ranking', 'engagement_ranking', 'conversion_ranking']);

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->ad_id] = [
                'quality'    => $r->quality_ranking !== null ? (string) $r->quality_ranking : null,
                'engagement' => $r->engagement_ranking !== null ? (string) $r->engagement_ranking : null,
                'conversion' => $r->conversion_ranking !== null ? (string) $r->conversion_ranking : null,
            ];
        }

        return $out;
    }

    /** @param array{quality: ?string, engagement: ?string, conversion: ?string} $rank */
    private function isBelowAverage(array $rank): bool
    {
        foreach (['quality', 'engagement', 'conversion'] as $k) {
            if (is_string($rank[$k]) && str_starts_with($rank[$k], 'below_average')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, float> $values */
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
     * Is the report's data current? Gated on the latest complete creative day on
     * file across the creative platforms — the data this report actually
     * renders. Same shape/contract as OverallPerformanceReport::freshness.
     *
     * @return array<string, mixed>
     */
    private function freshness(int $brandId, string $windowEnd): array
    {
        $lastComplete = AdCreativeDaily::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', self::CREATIVE_PLATFORMS)
            ->where('is_complete', true)
            ->max('date');

        $end  = CarbonImmutable::parse($windowEnd)->startOfDay();
        $last = $lastComplete !== null ? CarbonImmutable::parse((string) $lastComplete)->startOfDay() : null;

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
