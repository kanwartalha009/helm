<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CountryRevenueSpend;
use App\Reports\Mom\Support\RangeCollapse;
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

        // Custom range (Kanwar, 2026-07-17): a sub-month range can't be a monthly
        // matrix, so collapse to the tier revenue over the range vs the same range
        // last year.
        if ($filters->isCustomRange()) {
            return $this->rangeCollapse($brand, $filters, $tz);
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

    /** Collapse S4 to tier revenue over the custom range vs the same range last year. */
    private function rangeCollapse(Brand $brand, ReportFilters $filters, string $tz): array
    {
        $range = $filters->activeWindow($tz);
        $cmp   = $filters->activeComparisonWindow($tz);
        if ($range === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }

        $joiner   = new CountryRevenueSpend();
        $tierDefs = (new CountryTiers())->resolve($brand);
        $rangeByTier = $this->rollUp($joiner->compute($brand->id, $range[0], $range[1]), $tierDefs);
        if ($rangeByTier === []) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No commerce-by-country data in the selected range.'];
        }
        $yoyByTier = $cmp !== null ? $this->rollUp($joiner->compute($brand->id, $cmp[0], $cmp[1]), $tierDefs) : [];

        $groups = [];
        foreach ($rangeByTier as $key => $t) {
            $groups[] = ['label' => $t['label'], 'value' => $t['revenue'], 'compare' => $yoyByTier[$key]['revenue'] ?? null];
        }

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'range'  => true,
            'rangeCollapse' => RangeCollapse::revenueByGroup(
                RangeCollapse::label($range[0], $range[1]),
                $cmp !== null ? RangeCollapse::label($cmp[0], $cmp[1]) : 'Last year',
                $groups,
            ),
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
