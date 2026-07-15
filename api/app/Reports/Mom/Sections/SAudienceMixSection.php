<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\MetaBreakdownDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * REV2/M3 (monthly-report-v2-mom.md §M3) — "S13 Audience: new vs existing
 * spend (slide 10). Per campaign: spend split by Meta audience segments
 * (meta_breakdown_daily audience axis — already synced daily), Existing% chip
 * vs benchmark <15% (config), sorted by spend desc, AOV column included."
 *
 * DOCUMENTED DEVIATION from the literal spec text: `meta_breakdown_daily` has
 * no `campaign_id` column (verified against its migration this pass) — it is
 * brand+date+breakdown_type+segment grain, the same table the existing
 * Audience dashboard (`AudienceQuery`) already reads at brand level, never
 * per-campaign. "Per campaign" in the spec is not buildable on the current
 * schema without a new campaign-level breakdown sync (a real gap, not
 * something to fake). This section builds the BRAND-level split instead —
 * still the Existing% chip vs the 15% benchmark the spec cares about, just
 * not sliced by campaign. Segment vocabulary (prospecting/engaged/existing/
 * unknown → New/Engaged/Existing/Unknown, "Non-ASC" remainder) matches
 * `AudienceQuery::AUDIENCE_LABELS` exactly, reimplemented independently here
 * (REV2 R7 — no v1/shared dashboard files touched).
 *
 * AOV per segment is NOT buildable: there is no order-to-audience-segment
 * link in this schema (Shopify orders and Meta audience segments are never
 * joined at the row level) — logged unavailable, never fabricated.
 */
final class SAudienceMixSection implements MomSection
{
    /** @var array<string, string> raw user_segment_key => display label — matches AudienceQuery::AUDIENCE_LABELS */
    private const LABELS = [
        'prospecting' => 'New',
        'engaged'     => 'Engaged',
        'existing'    => 'Existing',
        'unknown'     => 'Unknown',
    ];

    public function key(): string
    {
        return 'S13';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->metrics($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Meta audience-segment data synced for this brand/month yet (meta:backfill-breakdown --type=audience).',
            ];
        }

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $this->metrics($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $benchmark = (float) config('momreport.benchmarks.existing_spend_pct_benchmark', 15.0);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'totalSpend' => $this->tile($cur['total'], $cmp['total'] ?? null),
            'existingPct' => $this->tile($cur['existingPct'], $cmp['existingPct'] ?? null),
            'benchmark' => $benchmark,
            // Alarm when MORE spend than the benchmark is going to already-existing
            // customers (prospecting budget should dominate) — null when there's no
            // spend to rate at all, never a false "pass".
            'alarm' => $cur['existingPct'] !== null ? $cur['existingPct'] > $benchmark : null,
            'segments' => $cur['segments'],
            'unavailable' => [
                'perCampaign' => 'meta_breakdown_daily has no campaign_id — this split is brand-level, not per-campaign (schema gap, see class docblock).',
                'aov'         => 'No order-to-audience-segment link exists in this schema.',
            ],
        ];
    }

    /** @return array{total: float, existingPct: ?float, segments: array<int, array<string, mixed>>}|null */
    private function metrics(int $brandId, string $start, string $end): ?array
    {
        $totalDisp = (float) DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COALESCE(SUM(spend), 0) AS v')
            ->value('v');

        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->where('breakdown_type', 'audience')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key')
            ->selectRaw('segment_key, COALESCE(SUM(spend), 0) AS spend')
            ->get();

        if ($rows->isEmpty() && $totalDisp <= 0.0) {
            return null;
        }

        $known = [];
        foreach ($rows as $r) {
            $known[(string) $r->segment_key] = (float) $r->spend;
        }

        $shownSum = 0.0;
        $segments = [];
        foreach (self::LABELS as $rawKey => $label) {
            if (! array_key_exists($rawKey, $known)) {
                continue; // never seen this ASC segment — don't render a dead row
            }
            $spend = round($known[$rawKey], 2);
            $shownSum += $spend;
            $segments[] = [
                'key' => $rawKey, 'label' => $label, 'spend' => $spend,
                'share' => $totalDisp > 0.0 ? round($spend / $totalDisp * 100, 1) : null,
            ];
        }

        $remainder = round(max($totalDisp - $shownSum, 0.0), 2);
        $segments[] = [
            'key' => '__non_asc', 'label' => 'Non-ASC', 'spend' => $remainder,
            'share' => $totalDisp > 0.0 ? round($remainder / $totalDisp * 100, 1) : null,
        ];
        usort($segments, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $existing = round($known['existing'] ?? 0.0, 2);

        return [
            'total' => round($totalDisp, 2),
            'existingPct' => $totalDisp > 0.0 ? round($existing / $totalDisp * 100, 1) : null,
            'segments' => $segments,
        ];
    }

    /** @return array{value: ?float, compare: ?float, deltaPct: ?float} */
    private function tile(?float $value, ?float $compareValue): array
    {
        $deltaPct = null;
        if ($value !== null && $compareValue !== null && $compareValue !== 0.0) {
            $deltaPct = round(($value - $compareValue) / $compareValue * 100, 1);
        }

        return ['value' => $value, 'compare' => $compareValue, 'deltaPct' => $deltaPct];
    }
}
