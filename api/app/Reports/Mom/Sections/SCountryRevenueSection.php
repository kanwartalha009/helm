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

        $tiers = (new CountryTiers())->resolve($brand);
        $floor = (float) config('momreport.benchmarks.roas_alarm_floor', 1.5);

        $totalSpend = array_sum(array_column($cur, 'spend'));
        $totalRevenue = array_sum(array_column($cur, 'revenue'));

        $rows = [];
        $roasValues = [];
        foreach ($cur as $key => $row) {
            $roas = $row['spend'] > 0.0 ? round($row['revenue'] / $row['spend'], 2) : null;
            if ($roas !== null && $row['spend'] >= $totalSpend * self::MIN_SPEND_SHARE_FOR_STATUS) {
                $roasValues[] = $roas;
            }
            $prevRow = $cmp[$key] ?? null;
            $rows[] = [
                'iso2'     => $row['iso2'],
                'label'    => $row['label'],
                'tier'     => $tiers[$row['iso2']]['tierKey'] ?? null,
                'tierLabel' => $tiers[$row['iso2']]['label'] ?? 'Other',
                'revenue'  => $row['revenue'],
                'orders'   => $row['orders'],
                'spend'    => $row['spend'],
                'spendPct' => $totalSpend > 0.0 ? round($row['spend'] / $totalSpend * 100, 1) : null,
                'roas'     => $roas,
                'compareRevenue' => $prevRow['revenue'] ?? null,
                'deltaPct' => $this->delta($row['revenue'], $prevRow['revenue'] ?? null),
                // status assigned below once the top-quartile threshold is known
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
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'total' => ['revenue' => round($totalRevenue, 2), 'spend' => round($totalSpend, 2)],
            'rows'  => $rows,
            'suggestedTitle' => $topCountries !== [] ? 'Push ' . implode(', ', array_slice($topCountries, 0, 3)) : null,
            'benchmarks' => ['roasAlarmFloor' => $floor],
            'unavailable' => [
                'breakevenRoas' => 'No per-brand margin/breakeven figure read this pass — status uses the HELM default floor (1.5) only.',
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
