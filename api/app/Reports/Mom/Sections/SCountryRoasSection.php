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
 * M2 (monthly-report-v2-mom.md §M2) — "S6 ROAS by country (slide 9)" — same
 * country x commerce/spend join as S5 (`CountryRevenueSpend`, shared so the
 * two sections never disagree on the underlying numbers).
 *
 * Kanwar, 2026-07-16: rebuilt as a MONTH-BY-MONTH matrix — one ROAS column per
 * month over the selected N-month window (control: ReportFilters::$months),
 * plus window ROAS, revenue, Meta spend, tier tag, ΔYoY / ΔMoM, and a TOP/CHECK/
 * ALARM status. Cells are graded against a configurable ROAS BENCHMARK
 * (ReportFilters::$benchmark, default the config alarm floor) — the "customise
 * the benchmark" control. Countries with no spend in the whole window are
 * omitted (ROAS undefined, never a fake 0).
 */
final class SCountryRoasSection implements MomSection
{
    /** Minimum share of total window spend a country needs before a TOP/ALARM verdict is meaningful. */
    private const MIN_SPEND_SHARE_FOR_STATUS = 0.02;

    public function key(): string
    {
        return 'S6';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';

        // Custom range (Kanwar, 2026-07-17): collapse to ROAS by country over the
        // range vs the same range last year.
        if ($filters->isCustomRange()) {
            return $this->rangeCollapse($brand, $filters, $tz);
        }

        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }

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

        $priorStart = $reportMonth->subMonths($n - 1)->subYear()->startOfMonth();
        $priorEnd   = $reportMonth->subYear()->endOfMonth();
        $priorYear  = $joiner->compute($brand->id, $priorStart->toDateString(), $priorEnd->toDateString());

        $tiers = (new CountryTiers())->resolve($brand);
        $benchmark = $filters->benchmark ?? (float) config('momreport.benchmarks.roas_alarm_floor', 1.5);

        $totalSpend = 0.0;
        foreach ($byCountry as $c) {
            foreach ($c['months'] as $m) {
                $totalSpend += $m['spend'];
            }
        }

        $rows = [];
        $roasValues = [];
        foreach ($byCountry as $key => $c) {
            $monthlyRoas = [];
            $rev = 0.0;
            $spend = 0.0;
            foreach ($months as $ym) {
                $mv = $c['months'][$ym] ?? null;
                $mRoas = ($mv !== null && $mv['spend'] > 0.0) ? round($mv['revenue'] / $mv['spend'], 2) : null;
                $monthlyRoas[] = $mRoas;
                $rev   += $mv['revenue'] ?? 0.0;
                $spend += $mv['spend'] ?? 0.0;
            }
            if ($spend <= 0.0) {
                continue; // no spend across the whole window → ROAS undefined, omit
            }
            $roas = round($rev / $spend, 2);
            if ($spend >= $totalSpend * self::MIN_SPEND_SHARE_FOR_STATUS) {
                $roasValues[] = $roas;
            }
            $priorRow  = $priorYear[$key] ?? null;
            $priorRoas = ($priorRow !== null && $priorRow['spend'] > 0.0) ? round($priorRow['revenue'] / $priorRow['spend'], 2) : null;

            $last = $monthlyRoas[$n - 1] ?? null;
            $prev = $n >= 2 ? ($monthlyRoas[$n - 2] ?? null) : null;

            $rows[] = [
                'iso2'      => $c['iso2'],
                'label'     => $c['label'],
                'tier'      => $tiers[$c['iso2']]['tierKey'] ?? null,
                'tierLabel' => $tiers[$c['iso2']]['label'] ?? 'Other',
                'monthly'   => $monthlyRoas, // ROAS per month, aligned to `months`
                'roas'      => $roas,
                'revenue'   => round($rev, 2),
                'spend'     => round($spend, 2),
                'deltaYoYPct' => $this->delta($roas, $priorRoas),
                'deltaMoMPct' => $this->delta($last, $prev),
            ];
        }

        sort($roasValues);
        $topQuartileFloor = $roasValues !== [] ? $roasValues[(int) floor(count($roasValues) * 0.75)] : null;

        foreach ($rows as &$row) {
            $eligible = $row['spend'] >= $totalSpend * self::MIN_SPEND_SHARE_FOR_STATUS;
            if (! $eligible) {
                $row['status'] = null;
            } elseif ($row['roas'] < $benchmark) {
                $row['status'] = 'ALARM';
            } elseif ($topQuartileFloor !== null && $row['roas'] >= $topQuartileFloor) {
                $row['status'] = 'TOP';
            } else {
                $row['status'] = 'CHECK';
            }
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => $b['roas'] <=> $a['roas']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => $reportMonth->format('Y-m'),
            'months' => $months,
            'monthLabels' => array_map(static fn (string $ym): string => CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->isoFormat('MMM YY'), $months),
            'monthsWindow' => $n,
            'benchmark' => round($benchmark, 2),
            'rows'   => $rows,
            'unavailable' => [
                'zeroSpendCountries' => 'Countries with revenue but no Meta spend are omitted (ROAS is undefined, not zero).',
            ],
        ];
    }

    /** Collapse S6 to ROAS by country over the custom range vs the same range last year. */
    private function rangeCollapse(Brand $brand, ReportFilters $filters, string $tz): array
    {
        $range = $filters->activeWindow($tz);
        $cmp   = $filters->activeComparisonWindow($tz);
        if ($range === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }

        $joiner = new CountryRevenueSpend();
        $rangeC = $joiner->compute($brand->id, $range[0], $range[1]);
        $yoyC   = $cmp !== null ? $joiner->compute($brand->id, $cmp[0], $cmp[1]) : [];

        $items = [];
        foreach ($rangeC as $key => $c) {
            if ($c['spend'] <= 0.0) {
                continue; // ROAS undefined without spend — omit, never a fake 0
            }
            $roas = round($c['revenue'] / $c['spend'], 2);
            $yc   = $yoyC[$key] ?? null;
            $yoyRoas = ($yc !== null && $yc['spend'] > 0.0) ? round($yc['revenue'] / $yc['spend'], 2) : null;
            $items[] = ['label' => $c['label'], 'roas' => $roas, 'yoyRoas' => $yoyRoas];
        }
        if ($items === []) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No ad spend by country in the selected range.'];
        }
        usort($items, static fn (array $a, array $b): int => $b['roas'] <=> $a['roas']);

        $rangeLabel   = RangeCollapse::label($range[0], $range[1]);
        $compareLabel = $cmp !== null ? RangeCollapse::label($cmp[0], $cmp[1]) : 'Last year';
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                RangeCollapse::cell($it['label'], 'text'),
                RangeCollapse::cell($it['roas'], 'ratio'),
                RangeCollapse::cell($it['yoyRoas'], 'ratio'),
                RangeCollapse::cell(RangeCollapse::delta($it['roas'], $it['yoyRoas']), 'delta'),
            ];
        }

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'range'  => true,
            'rangeCollapse' => RangeCollapse::table(
                $rangeLabel,
                $compareLabel,
                ['Country', 'ROAS · ' . $rangeLabel, 'ROAS · ' . $compareLabel, 'Δ YoY'],
                $rows,
            ),
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
