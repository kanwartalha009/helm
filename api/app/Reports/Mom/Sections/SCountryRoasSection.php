<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CountryRevenueSpend;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S6 ROAS by country (slide 9)" — same
 * country x commerce/spend join as S5 (`CountryRevenueSpend`, shared so the
 * two sections never disagree on the underlying numbers), sorted by ROAS
 * instead of revenue, with the country's ROAS ΔMoM/ΔYoY the headline instead
 * of its revenue delta.
 */
final class SCountryRoasSection implements MomSection
{
    public function key(): string
    {
        return 'S6';
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

        $rows = [];
        foreach ($cur as $key => $row) {
            if ($row['spend'] <= 0.0) {
                continue; // no ad spend in this country -> ROAS is undefined, not zero (omit the row)
            }
            $roas = round($row['revenue'] / $row['spend'], 2);
            $prevRow = $cmp[$key] ?? null;
            $prevRoas = ($prevRow !== null && $prevRow['spend'] > 0.0) ? round($prevRow['revenue'] / $prevRow['spend'], 2) : null;

            $rows[] = [
                'iso2'    => $row['iso2'],
                'label'   => $row['label'],
                'revenue' => $row['revenue'],
                'spend'   => $row['spend'],
                'roas'    => $roas,
                'compareRoas' => $prevRoas,
                'deltaPct' => $this->delta($roas, $prevRoas),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['roas'] <=> $a['roas']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'rows'   => $rows,
            'unavailable' => [
                'zeroSpendCountries' => 'Countries with revenue but no Meta spend are omitted (ROAS is undefined, not zero).',
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
}
