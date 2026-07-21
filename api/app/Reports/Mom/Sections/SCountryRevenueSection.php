<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CountryRevenueSpend;
use App\Reports\Mom\Support\RangeCollapse;
use App\Reports\Mom\Support\WeekSplit;
use App\Services\CountryTiers;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S5 Country revenue MoM (slide 8)...
 * Country x month revenue matrix w/ tier tag column, per-country ROAS,
 * ΔYoY/ΔMoM, Meta spend % by country; status column TOP/CHECK/ALARM from
 * deterministic rules (config: ALARM = ROAS < breakeven... or <1.5 [HELM
 * DEFAULT] with spend >= floor; TOP = top-quartile ROAS at meaningful spend)
 * — title auto-suggests 'Push {countries}' from TOP list."
 *
 * "Breakeven (margin set)" is per-brand-margin data this pass doesn't read
 * (GO-1.2 product costs exist per-PRODUCT, not resolved to a brand-level
 * breakeven ROAS here) — status uses the HELM DEFAULT floor only
 * (`roas_alarm_floor` = 1.5), never a fabricated margin-based threshold.
 * "Meaningful spend" floor for TOP/ALARM eligibility is the same 15% used
 * elsewhere in this program's benchmarks config as a stand-in "material
 * share" cut — countries below it render with a status of null, not a
 * guessed classification off noise.
 */
final class SCountryRevenueSection implements MomSection
{
    /** Minimum share of the brand's total Meta spend a country needs before a TOP/ALARM verdict is meaningful. */
    private const MIN_SPEND_SHARE_FOR_STATUS = 0.02; // 2% of total spend

    public function key(): string
    {
        return 'S5';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';

        // Custom range (Kanwar, 2026-07-20): week-on-week revenue by country —
        // one column per ISO week across the range (the running month included,
        // up to yesterday) instead of monthly columns.
        if ($filters->isCustomRange()) {
            return $this->weekly($brand, $filters, $tz);
        }

        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }

        // Month-by-month matrix (Kanwar, 2026-07-16): the last N months ending at
        // the report month, N controlled by the section's own window selector
        // (ReportFilters::$months, same control S1 uses); default 6.
        $reportMonth = CarbonImmutable::parse($window[0], $tz)->startOfMonth();
        $n = $this->windowLength($filters->months);
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

        // Same N months one year earlier — a single windowed compute per country
        // for the ΔYoY column (window total vs the same window last year).
        $priorStart = $reportMonth->subMonths($n - 1)->subYear()->startOfMonth();
        $priorEnd   = $reportMonth->subYear()->endOfMonth();
        $priorYear  = $joiner->compute($brand->id, $priorStart->toDateString(), $priorEnd->toDateString());

        $tiers = (new CountryTiers())->resolve($brand);
        $floor = (float) config('momreport.benchmarks.roas_alarm_floor', 1.5);

        // Window totals (sum of the N months) per country, and brand-wide totals.
        $totalRevenue = 0.0;
        $totalSpend   = 0.0;
        foreach ($byCountry as $c) {
            foreach ($c['months'] as $m) {
                $totalRevenue += $m['revenue'];
                $totalSpend   += $m['spend'];
            }
        }

        $rows = [];
        $roasValues = [];
        foreach ($byCountry as $key => $c) {
            $monthly = [];
            $rev = 0.0;
            $spend = 0.0;
            foreach ($months as $ym) {
                $mv = $c['months'][$ym] ?? null;
                $monthly[] = $mv !== null ? round((float) $mv['revenue'], 2) : null;
                $rev   += $mv['revenue'] ?? 0.0;
                $spend += $mv['spend'] ?? 0.0;
            }
            $roas = $spend > 0.0 ? round($rev / $spend, 2) : null;
            if ($roas !== null && $spend >= $totalSpend * self::MIN_SPEND_SHARE_FOR_STATUS) {
                $roasValues[] = $roas;
            }

            // ΔMoM = the last month vs the month before it (the momentum column).
            $last = $monthly[$n - 1] ?? null;
            $prev = $n >= 2 ? ($monthly[$n - 2] ?? null) : null;

            $rows[] = [
                'iso2'      => $c['iso2'],
                'label'     => $c['label'],
                'tier'      => $tiers[$c['iso2']]['tierKey'] ?? null,
                'tierLabel' => $tiers[$c['iso2']]['label'] ?? 'Other',
                'monthly'   => $monthly, // aligned to `months`, null where no data
                'revenue'   => round($rev, 2),
                'spend'     => round($spend, 2),
                'sharePct'  => $totalRevenue > 0.0 ? round($rev / $totalRevenue * 100, 1) : null,
                'spendPct'  => $totalSpend > 0.0 ? round($spend / $totalSpend * 100, 1) : null,
                'roas'      => $roas,
                'deltaYoYPct' => $this->delta($rev, $priorYear[$key]['revenue'] ?? null),
                'deltaMoMPct' => $this->delta($last, $prev),
            ];
        }

        // Top-quartile ROAS among countries with meaningful spend — the TOP bar.
        sort($roasValues);
        $topQuartileFloor = $roasValues !== [] ? $roasValues[(int) floor(count($roasValues) * 0.75)] : null;

        $topCountries = [];
        foreach ($rows as &$row) {
            $eligible = $row['roas'] !== null && $row['spend'] >= $totalSpend * self::MIN_SPEND_SHARE_FOR_STATUS;
            if (! $eligible) {
                $row['status'] = null;
                continue;
            }
            if ($row['roas'] < $floor) {
                $row['status'] = 'ALARM';
            } elseif ($topQuartileFloor !== null && $row['roas'] >= $topQuartileFloor) {
                $row['status'] = 'TOP';
                $topCountries[] = $row['label'];
            } else {
                $row['status'] = 'CHECK';
            }
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => $reportMonth->format('Y-m'),
            'months' => $months,
            'monthLabels' => array_map(static fn (string $ym): string => CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->isoFormat('MMM YY'), $months),
            'monthsWindow' => $n,
            'total' => ['revenue' => round($totalRevenue, 2), 'spend' => round($totalSpend, 2)],
            'rows'  => $rows,
            'suggestedTitle' => $topCountries !== [] ? 'Push ' . implode(', ', array_slice($topCountries, 0, 3)) : null,
            'benchmarks' => ['roasAlarmFloor' => $floor],
            'unavailable' => [
                'breakevenRoas' => 'No per-brand margin/breakeven figure read this pass — status uses the HELM default floor (1.5) only.',
            ],
        ];
    }

    /** Week-on-week revenue by country across the custom range. */
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

        $joiner = new CountryRevenueSpend();
        $rangeC = $joiner->compute($brand->id, $range[0], $range[1]);
        if ($rangeC === []) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No commerce-by-country data in the selected range.'];
        }

        $perWeek = [];
        foreach ($weeks as $w) {
            $perWeek[] = $joiner->compute($brand->id, $w['start'], $w['end']);
        }

        $groups = [];
        foreach ($rangeC as $key => $c) {
            $cells = [];
            foreach ($perWeek as $wk) {
                $cells[] = isset($wk[$key]) ? round((float) $wk[$key]['revenue'], 2) : null;
            }
            $groups[] = ['label' => $c['label'], 'weekly' => $cells, 'total' => round((float) $c['revenue'], 2)];
        }

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'range'  => true,
            'rangeCollapse' => RangeCollapse::weeklyRevenueByGroup(
                'Country',
                array_column($weeks, 'label'),
                $groups,
                'Week-on-week revenue by country',
                WeekSplit::note($weeks),
            ),
        ];
    }

    /** Clamp the section's month-window selector to a sane 1..12; default 6. */
    private function windowLength(?int $months): int
    {
        if ($months === null) {
            return 6;
        }

        return max(1, min(12, $months));
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
    }
}
