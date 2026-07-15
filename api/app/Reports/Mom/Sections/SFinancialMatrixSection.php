<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S1 Financial matrix (PDF slide 4 —
 * ALWAYS FIRST by default). Rows = every month of report year + full prior
 * year (two stacked tables). Columns: Orders, AOV, %Returns, Revenue, Spend,
 * Google share % of spend, blended ROAS, New/Returning customers, %Ret, Total
 * customers, CAC, ROAS-nc, MoM deltas. New/Returning needs the ShopifyQL
 * customer_type PROBE — if unavailable, render the matrix WITHOUT those
 * columns + an honest note, never fake them. Heatmap cells (green/red vs
 * prior month). Summary callout row auto-computed."
 *
 * The customer_type probe has NOT been run in this session (it needs live
 * production Shopify ShopifyQL access this sandbox does not have) — per the
 * spec's OWN fallback rule, this section renders WITHOUT New/Returning/%Ret/
 * Total customers/CAC/ROAS-nc, with an explicit `unavailable` note, rather
 * than waiting on a probe nobody can run from here. "Google share % of spend"
 * IS a real column (Google is a synced platform, no probe needed).
 *
 * MoM/YoY deltas compare each month to the calendar month immediately before
 * it (queried directly, not "the previous row in this table") so January's
 * delta still resolves against the prior December even though it sits in a
 * different stacked table. Heatmap flags are 'up'/'down'/null on revenue and
 * blended ROAS only — the two cells the PDF actually colors.
 */
final class SFinancialMatrixSection implements MomSection
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    public function key(): string
    {
        return 'S1';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        $reportMonth = CarbonImmutable::parse($window[0], $tz);
        $reportYear  = (int) $reportMonth->format('Y');
        $priorYear   = $reportYear - 1;

        // One extra month of lookback (December of priorYear-1) purely to give
        // January of priorYear a MoM delta to compare against.
        $queryStart = CarbonImmutable::create($priorYear - 1, 12, 1, 0, 0, 0, $tz);
        $queryEnd   = $reportMonth->endOfMonth();

        $byMonth = $this->monthlyMetrics($brand->id, $queryStart->toDateString(), $queryEnd->toDateString());
        if ($byMonth === []) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Shopify/ad data synced for this brand in the report or prior year yet.',
            ];
        }

        $currentYearRows = $this->buildRows($byMonth, $reportYear, 1, (int) $reportMonth->format('n'));
        $priorYearRows   = $this->buildRows($byMonth, $priorYear, 1, 12);

        $reportRow = $byMonth[$reportMonth->format('Y-m')] ?? null;
        $priorYearSameMonthKey = $reportMonth->subYear()->format('Y-m');
        $priorYearSameMonth = $byMonth[$priorYearSameMonthKey] ?? null;

        $summary = [
            'revenue'      => $reportRow['revenue'] ?? null,
            'revenueYoYPct' => ($reportRow !== null && $priorYearSameMonth !== null)
                ? $this->pctDelta($reportRow['revenue'], $priorYearSameMonth['revenue']) : null,
            'roas' => ($reportRow !== null && $reportRow['spend'] > 0.0) ? round($reportRow['revenue'] / $reportRow['spend'], 2) : null,
            'aov'  => ($reportRow !== null && $reportRow['orders'] > 0) ? round($reportRow['revenue'] / $reportRow['orders'], 2) : null,
        ];

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => $reportMonth->format('Y-m'),
            'reportYear' => $reportYear,
            'priorYear'  => $priorYear,
            'currentYearRows' => $currentYearRows,
            'priorYearRows'   => $priorYearRows,
            'summary' => $summary,
            'unavailable' => [
                'customerColumns' => 'New/Returning/%Ret/Total customers columns need the ShopifyQL customer_type probe — not run this session (requires live production access).',
                'cac'    => 'Needs the same customer_type probe as customerColumns.',
                'roasNc' => 'Needs the same customer_type probe as customerColumns.',
            ],
        ];
    }

    /**
     * @param array<string, array{orders:int, revenue:float, refunds:float, spend:float, googleSpend:float}> $byMonth
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $byMonth, int $year, int $fromMonth, int $toMonth): array
    {
        $rows = [];
        for ($m = $fromMonth; $m <= $toMonth; $m++) {
            $key = sprintf('%04d-%02d', $year, $m);
            $cur = $byMonth[$key] ?? null;

            $prevDate = CarbonImmutable::createFromDate($year, $m, 1)->subMonth();
            $prevKey  = $prevDate->format('Y-m');
            $prev = $byMonth[$prevKey] ?? null;

            if ($cur === null) {
                $rows[] = ['month' => $key, 'label' => CarbonImmutable::createFromDate($year, $m, 1)->isoFormat('MMMM YYYY'), 'status' => 'no_data'];
                continue;
            }

            $roas = $cur['spend'] > 0.0 ? round($cur['revenue'] / $cur['spend'], 2) : null;
            $prevRoas = ($prev !== null && $prev['spend'] > 0.0) ? round($prev['revenue'] / $prev['spend'], 2) : null;

            $rows[] = [
                'month'  => $key,
                'label'  => CarbonImmutable::createFromDate($year, $m, 1)->isoFormat('MMMM YYYY'),
                'status' => 'ok',
                'orders' => $cur['orders'],
                'aov'    => $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 2) : null,
                'returnsPct' => $cur['revenue'] > 0.0 ? round(abs($cur['refunds']) / $cur['revenue'] * 100, 1) : null,
                'revenue' => round($cur['revenue'], 2),
                'spend'   => round($cur['spend'], 2),
                'googleSharePct' => $cur['spend'] > 0.0 ? round($cur['googleSpend'] / $cur['spend'] * 100, 1) : null,
                'roas'    => $roas,
                'deltaRevenuePct' => $prev !== null ? $this->pctDelta($cur['revenue'], $prev['revenue']) : null,
                'deltaSpendPct'   => $prev !== null ? $this->pctDelta($cur['spend'], $prev['spend']) : null,
                'deltaRoasPct'    => $this->pctDelta($roas, $prevRoas),
                'revenueFlag' => $this->flag($cur['revenue'], $prev['revenue'] ?? null),
                'roasFlag'    => $this->flag($roas, $prevRoas),
            ];
        }

        return $rows;
    }

    /** @return array<string, array{orders:int, revenue:float, refunds:float, spend:float, googleSpend:float}> */
    private function monthlyMetrics(int $brandId, string $start, string $end): array
    {
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005

        $shopifyRows = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("date, {$revCol} AS revenue, COALESCE(refunds_amount, 0) AS refunds, COALESCE(orders, 0) AS orders")
            ->get();

        $adRows = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', self::AD_PLATFORMS)
            ->whereBetween('date', [$start, $end])
            ->selectRaw('date, platform, COALESCE(spend, 0) AS spend')
            ->get();

        $out = [];
        foreach ($shopifyRows as $r) {
            $key = CarbonImmutable::parse((string) $r->date)->format('Y-m');
            $out[$key] ??= ['orders' => 0, 'revenue' => 0.0, 'refunds' => 0.0, 'spend' => 0.0, 'googleSpend' => 0.0];
            $out[$key]['orders']  += (int) $r->orders;
            $out[$key]['revenue'] += (float) $r->revenue;
            $out[$key]['refunds'] += (float) $r->refunds;
        }
        foreach ($adRows as $r) {
            $key = CarbonImmutable::parse((string) $r->date)->format('Y-m');
            $out[$key] ??= ['orders' => 0, 'revenue' => 0.0, 'refunds' => 0.0, 'spend' => 0.0, 'googleSpend' => 0.0];
            $out[$key]['spend'] += (float) $r->spend;
            if ($r->platform === 'google') {
                $out[$key]['googleSpend'] += (float) $r->spend;
            }
        }

        return $out;
    }

    private function pctDelta(?float $cur, ?float $prev): ?float
    {
        if ($cur === null || $prev === null || $prev === 0.0) {
            return null;
        }

        return round(($cur - $prev) / $prev * 100, 1);
    }

    private function flag(?float $cur, ?float $prev): ?string
    {
        if ($cur === null || $prev === null) {
            return null;
        }
        if ($cur > $prev) {
            return 'up';
        }
        if ($cur < $prev) {
            return 'down';
        }

        return 'flat';
    }
}
