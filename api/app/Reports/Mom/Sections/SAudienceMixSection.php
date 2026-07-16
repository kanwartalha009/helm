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

        // Month-by-month matrix (Kanwar, 2026-07-16): audience segment × the last N
        // months (window control) of Meta spend, + Total/Share/ΔMoM/ΔYoY per segment.
        $reportMonth = CarbonImmutable::parse($window[0], $tz)->startOfMonth();
        $n = $filters->months === null ? 6 : max(1, min(12, $filters->months));
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = $reportMonth->subMonths($i)->format('Y-m');
        }

        // Per-month segment split (reuses the single-window metrics()).
        $monthData = [];
        $any = false;
        foreach ($months as $ym) {
            $mStart = CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->startOfMonth();
            $md = $this->metrics($brand->id, $mStart->toDateString(), $mStart->endOfMonth()->toDateString());
            $monthData[$ym] = $md;
            $any = $any || $md !== null;
        }
        if (! $any) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Meta audience-segment data synced for this brand/month yet (meta:backfill-breakdown --type=audience).',
            ];
        }

        // Prior-year window (same N months) for ΔYoY, per segment.
        $priorStart = $reportMonth->subMonths($n - 1)->subYear()->startOfMonth();
        $priorEnd   = $reportMonth->subYear()->endOfMonth();
        $prior = $this->metrics($brand->id, $priorStart->toDateString(), $priorEnd->toDateString());
        $priorBySeg = [];
        foreach ($prior['segments'] ?? [] as $s) {
            $priorBySeg[$s['key']] = $s['spend'];
        }

        // Assemble segment → monthly spend + window total; brand window total.
        $segMap = [];
        $monthTotals = [];
        $windowTotal = 0.0;
        foreach ($months as $ym) {
            $md = $monthData[$ym];
            $monthTotals[$ym] = $md['total'] ?? 0.0;
            $windowTotal += $md['total'] ?? 0.0;
            foreach ($md['segments'] ?? [] as $s) {
                $segMap[$s['key']] ??= ['key' => $s['key'], 'label' => $s['label'], 'months' => [], 'total' => 0.0];
                $segMap[$s['key']]['months'][$ym] = $s['spend'];
                $segMap[$s['key']]['total'] += $s['spend'];
            }
        }

        $rows = [];
        foreach ($segMap as $seg) {
            $monthly = [];
            foreach ($months as $ym) {
                $monthly[] = array_key_exists($ym, $seg['months']) ? round((float) $seg['months'][$ym], 2) : null;
            }
            $lastM = $monthly[$n - 1] ?? null;
            $prevM = $n >= 2 ? ($monthly[$n - 2] ?? null) : null;
            $rows[] = [
                'key'      => $seg['key'],
                'label'    => $seg['label'],
                'monthly'  => $monthly,
                'spend'    => round($seg['total'], 2),
                'share'    => $windowTotal > 0.0 ? round($seg['total'] / $windowTotal * 100, 1) : null,
                'deltaMoMPct' => $this->delta($lastM, $prevM),
                'deltaYoYPct' => $this->delta($seg['total'], $priorBySeg[$seg['key']] ?? null),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $benchmark = (float) config('momreport.benchmarks.existing_spend_pct_benchmark', 15.0);
        $existingWindow = $segMap['existing']['total'] ?? 0.0;
        $existingPct = $windowTotal > 0.0 ? round($existingWindow / $windowTotal * 100, 1) : null;

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => $reportMonth->format('Y-m'),
            'months' => $months,
            'monthLabels' => array_map(static fn (string $ym): string => CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->isoFormat('MMM YY'), $months),
            'monthsWindow' => $n,
            'totalSpend' => round($windowTotal, 2),
            'existingPct' => $existingPct,
            'benchmark' => $benchmark,
            // Alarm when MORE spend than the benchmark is going to already-existing
            // customers (prospecting budget should dominate) — null when there's no
            // spend to rate at all, never a false "pass".
            'alarm' => $existingPct !== null ? $existingPct > $benchmark : null,
            'rows'   => $rows,
            'unavailable' => [
                'perCampaign' => 'meta_breakdown_daily has no campaign_id — this split is brand-level, not per-campaign (schema gap, see class docblock).',
                'aov'         => 'No order-to-audience-segment link exists in this schema.',
            ],
        ];
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
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
