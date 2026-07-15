<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CountryRevenueSpend;
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
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $joiner = new CountryRevenueSpend();
        $cur = $joiner->compute($brand->id, $start, $end);
        if ($cur === []) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No commerce-by-country data synced for this brand/month yet (shopify:backfill-commerce).',
            ];
        }

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $joiner->compute($brand->id, $compareWindow[0], $compareWindow[1]) : [];

        $tierDefs = (new CountryTiers())->resolve($brand);

        $curByTier = $this->rollUp($cur, $tierDefs);
        $cmpByTier = $this->rollUp($cmp, $tierDefs);

        $totalRevenue = array_sum(array_column($curByTier, 'revenue'));

        $rows = [];
        foreach ($curByTier as $tierKey => $t) {
            $prev = $cmpByTier[$tierKey] ?? null;
            $roas = $t['spend'] > 0.0 ? round($t['revenue'] / $t['spend'], 2) : null;
            $rows[] = [
                'tierKey'  => $tierKey,
                'label'    => $t['label'],
                'color'    => $t['color'],
                'revenue'  => round($t['revenue'], 2),
                'share'    => $totalRevenue > 0.0 ? round($t['revenue'] / $totalRevenue * 100, 1) : null,
                'spend'    => round($t['spend'], 2),
                'roas'     => $roas,
                'deltaMoMPct' => $this->delta($t['revenue'], $prev['revenue'] ?? null),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'total'  => round($totalRevenue, 2),
            'rows'   => $rows,
            'unavailable' => [
                'deltaYTD' => 'Needs a full-year rolling aggregation not computed this pass.',
            ],
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
