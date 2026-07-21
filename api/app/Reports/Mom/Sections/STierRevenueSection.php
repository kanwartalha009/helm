<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CountryRevenueSpend;
use App\Reports\Mom\Support\WeekSplit;
use App\Services\CountryTiers;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S4 Market revenue by TIER (slide 7).
 * Tier x month matrix: revenue share %, revenue, ΔMoM/ΔYoY/ΔYTD, ROAS by tier
 * by month (Meta spend by country breakdown / revenue by country, both
 * already synced). Uses CountryTiers::resolve."
 *
 * ΔYTD is NOT built this pass — it needs a full-year rolling aggregation this
 * section doesn't do (only the base + one compare window); logged unavailable
 * rather than approximated from two months. Reuses the same country-level
 * join as S5/S6 (`CountryRevenueSpend`) so a country's tier-rollup revenue and
 * its own S5 row always reconcile to the same source numbers.
 */
final class STierRevenueSection implements MomSection
{
    public function key(): string
    {
        return 'S4';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';

        // Custom range (Kanwar, 2026-07-20): week-on-week tier revenue — one
        // column per ISO week across the range (running month included) instead
        // of monthly columns.
        if ($filters->isCustomRange()) {
            return $this->weekly($brand, $filters, $tz);
        }

        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }

        // Month-by-month tier matrix (Kanwar, 2026-07-16): tiers × the last N
        // months (window control), then window total / share / ROAS / ΔMoM / ΔYoY.
        $reportMonth = CarbonImmutable::parse($window[0], $tz)->startOfMonth();
        $n = $filters->months === null ? 6 : max(1, min(12, $filters->months));
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = $reportMonth->subMonths($i)->format('Y-m');
        }

        $joiner = new CountryRevenueSpend();
        $byCountry = $joiner->computeMonths($brand->id, $months);
        if ($byCountry === []) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No commerce-by-country data synced for this brand/month yet (shopify:backfill-commerce).',
            ];
        }

        $tierDefs = (new CountryTiers())->resolve($brand);

        // Roll countries up into tiers PER MONTH, plus a window total per tier.
        $byTier = []; // tierKey => ['label','color','months'=>[ym=>['revenue','spend']],'revenue','spend']
        foreach ($byCountry as $c) {
            $tier  = $tierDefs[$c['iso2']] ?? null;
            $key   = $tier['tierKey'] ?? '__other';
            $byTier[$key] ??= [
                'label' => $tier['label'] ?? 'Other',
                'color' => $tier['color'] ?? '#9CA3AF',
                'months' => [],
                'revenue' => 0.0,
                'spend' => 0.0,
            ];
            foreach ($c['months'] as $ym => $m) {
                $byTier[$key]['months'][$ym] ??= ['revenue' => 0.0, 'spend' => 0.0];
                $byTier[$key]['months'][$ym]['revenue'] += $m['revenue'];
                $byTier[$key]['months'][$ym]['spend']   += $m['spend'];
                $byTier[$key]['revenue'] += $m['revenue'];
                $byTier[$key]['spend']   += $m['spend'];
            }
        }

        // Same N months last year, rolled up by tier, for ΔYoY.
        $priorStart = $reportMonth->subMonths($n - 1)->subYear()->startOfMonth();
        $priorEnd   = $reportMonth->subYear()->endOfMonth();
        $priorByTier = $this->rollUp($joiner->compute($brand->id, $priorStart->toDateString(), $priorEnd->toDateString()), $tierDefs);

        $totalRevenue = array_sum(array_column($byTier, 'revenue'));

        $rows = [];
        foreach ($byTier as $tierKey => $t) {
            $monthly = [];
            foreach ($months as $ym) {
                $monthly[] = isset($t['months'][$ym]) ? round((float) $t['months'][$ym]['revenue'], 2) : null;
            }
            $last = $monthly[$n - 1] ?? null;
            $prev = $n >= 2 ? ($monthly[$n - 2] ?? null) : null;
            $roas = $t['spend'] > 0.0 ? round($t['revenue'] / $t['spend'], 2) : null;

            $rows[] = [
                'tierKey'  => $tierKey,
                'label'    => $t['label'],
                'color'    => $t['color'],
                'monthly'  => $monthly,
                'revenue'  => round($t['revenue'], 2),
                'share'    => $totalRevenue > 0.0 ? round($t['revenue'] / $totalRevenue * 100, 1) : null,
                'spend'    => round($t['spend'], 2),
                'roas'     => $roas,
                'deltaMoMPct' => $this->delta($last, $prev),
                'deltaYoYPct' => $this->delta($t['revenue'], $priorByTier[$tierKey]['revenue'] ?? null),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => $reportMonth->format('Y-m'),
            'months' => $months,
            'monthLabels' => array_map(static fn (string $ym): string => CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->isoFormat('MMM YY'), $months),
            'monthsWindow' => $n,
            'total'  => round($totalRevenue, 2),
            'rows'   => $rows,
            'unavailable' => [
                'deltaYTD' => 'Needs a full-year rolling aggregation not computed this pass.',
            ],
        ];
    }

    /**
     * Week-on-week tier revenue across the custom range (Kanwar, 2026-07-21).
     * Emits the SAME payload shape as the month-by-month build() — tier rows with
     * a per-week `monthly[]` plus Total / Share / ROAS / ΔYoY / ΔMoM — with
     * `weekly => true` and `weekHeaders`, so the existing S4 renderer draws every
     * column with weeks as the periods.
     */
    private function weekly(Brand $brand, ReportFilters $filters, string $tz): array
    {
        $range = $filters->activeWindow($tz);
        if ($range === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }
        $weeks = WeekSplit::windows($range[0], $range[1], $tz);
        if ($weeks === []) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }

        $joiner   = new CountryRevenueSpend();
        $tierDefs = (new CountryTiers())->resolve($brand);
        $rangeByTier = $this->rollUp($joiner->compute($brand->id, $range[0], $range[1]), $tierDefs);
        if ($rangeByTier === []) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No commerce-by-country data in the selected range.'];
        }

        // Same range one year earlier, rolled up by tier, for ΔYoY.
        $priorStart = CarbonImmutable::parse($range[0], $tz)->subYear()->toDateString();
        $priorEnd   = CarbonImmutable::parse($range[1], $tz)->subYear()->toDateString();
        $priorByTier = $this->rollUp($joiner->compute($brand->id, $priorStart, $priorEnd), $tierDefs);

        $perWeek = [];
        foreach ($weeks as $w) {
            $perWeek[] = $this->rollUp($joiner->compute($brand->id, $w['start'], $w['end']), $tierDefs);
        }

        $totalRevenue = array_sum(array_column($rangeByTier, 'revenue'));

        $rows = [];
        foreach ($rangeByTier as $key => $t) {
            $cells = [];
            foreach ($perWeek as $wk) {
                $cells[] = isset($wk[$key]) ? round((float) $wk[$key]['revenue'], 2) : null;
            }
            $rev   = (float) $t['revenue'];
            $spend = (float) $t['spend'];

            $rows[] = [
                'tierKey' => $key,
                'label'   => $t['label'],
                'color'   => $t['color'],
                'monthly' => $cells,
                'revenue' => round($rev, 2),
                'share'   => $totalRevenue > 0.0 ? round($rev / $totalRevenue * 100, 1) : null,
                'spend'   => round($spend, 2),
                'roas'    => $spend > 0.0 ? round($rev / $spend, 2) : null,
                'deltaMoMPct' => WeekSplit::lastWeekDelta($cells),
                'deltaYoYPct' => $this->delta($rev, isset($priorByTier[$key]) ? (float) $priorByTier[$key]['revenue'] : null),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        $periods = WeekSplit::periods($weeks);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'range'  => true,
            'weekly' => true,
            'months' => $periods['months'],
            'monthLabels' => $periods['monthLabels'],
            'weekHeaders' => $periods['weekHeaders'],
            'monthsWindow' => count($weeks),
            'total'  => round($totalRevenue, 2),
            'rows'   => $rows,
            'note'   => WeekSplit::note($weeks),
        ];
    }

    /**
     * @param array<string, array{iso2: string, label: string, revenue: float, orders: int, spend: float}> $countries
     * @param array<string, array{tierKey: string, label: string, color: string}> $tierDefs
     * @return array<string, array{label: string, color: string, revenue: float, spend: float}>
     */
    private function rollUp(array $countries, array $tierDefs): array
    {
        $out = [];
        foreach ($countries as $c) {
            $tier = $tierDefs[$c['iso2']] ?? null;
            $key   = $tier['tierKey'] ?? '__other';
            $label = $tier['label'] ?? 'Other';
            $color = $tier['color'] ?? '#9CA3AF';

            $out[$key] ??= ['label' => $label, 'color' => $color, 'revenue' => 0.0, 'spend' => 0.0];
            $out[$key]['revenue'] += $c['revenue'];
            $out[$key]['spend']   += $c['spend'];
        }

        return $out;
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
    }
}
