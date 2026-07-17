<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CustomerMix;
use App\Reports\Mom\Support\RangeCollapse;
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
 *
 * M5 addendum (Kanwar, 2026-07-15 — "last 3/4/6/12 month comparison with
 * previous year"): when `ReportFilters::$months` is set, both stacked tables
 * become a TRAILING N-month window ending at the report month (current) and
 * the same N-month window one year earlier (prior) — a true apples-to-apples
 * N-vs-N comparison, rather than always-Jan-start full-year blocks. This is
 * additive: `months` unset (the default) reproduces the exact original
 * behaviour byte-for-byte.
 */
final class SFinancialMatrixSection implements MomSection
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    public function __construct(private readonly CustomerMix $customerMix)
    {
    }

    public function key(): string
    {
        return 'S1';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';

        // Custom range (Kanwar, 2026-07-17): the monthly matrix can't be a
        // sub-month window, so collapse to the headline financials over the range
        // vs the same range last year. Customer-split columns need whole months
        // (the ShopifyQL customer feed is month-granular) so they're omitted here
        // with an honest note rather than approximated.
        if ($filters->isCustomRange()) {
            return $this->rangeCollapse($brand, $filters, $tz);
        }

        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        $reportMonth = CarbonImmutable::parse($window[0], $tz);
        $reportYear  = (int) $reportMonth->format('Y');
        $priorYear   = $reportYear - 1;
        $months      = $filters->months; // M5: trailing-window length, or null for the default full-year tables

        if ($months !== null) {
            $priorWindowEnd = $reportMonth->subYear();
            // One extra month of lookback before the PRIOR window's own start,
            // purely so its first row still gets a real MoM delta.
            $queryStart = $priorWindowEnd->subMonths($months - 1)->subMonth();
        } else {
            // One extra month of lookback (December of priorYear-1) purely to give
            // January of priorYear a MoM delta to compare against.
            $queryStart = CarbonImmutable::create($priorYear - 1, 12, 1, 0, 0, 0, $tz);
        }
        $queryEnd = $reportMonth->endOfMonth();

        $byMonth = $this->monthlyMetrics($brand->id, $queryStart->toDateString(), $queryEnd->toDateString());
        if ($byMonth === []) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Shopify/ad data synced for this brand in the report or prior year yet.',
            ];
        }

        // Per-month new/returning customer COUNTS across the whole query window,
        // in one bounded live ShopifyQL call. Empty when the brand has no Shopify
        // connection / read_reports scope — the customer columns then render "—"
        // (honestly unavailable), never fabricated (spec S1's own fallback rule).
        $counts = $this->customerMix->forRange($brand, $queryStart->toDateString(), $queryEnd->toDateString());
        // Per-month revenue-goal targets (an explicit month target wins; else the
        // brand's standing default). Goal shows only where a target exists.
        $targets = $this->targetsFor($brand->id);

        if ($months !== null) {
            $currentYearRows = $this->buildTrailingRows($byMonth, $counts, $targets, $reportMonth, $months);
            $priorYearRows   = $this->buildTrailingRows($byMonth, $counts, $targets, $reportMonth->subYear(), $months);
        } else {
            $currentYearRows = $this->buildRows($byMonth, $counts, $targets, $reportYear, 1, (int) $reportMonth->format('n'));
            $priorYearRows   = $this->buildRows($byMonth, $counts, $targets, $priorYear, 1, 12);
        }

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
            'monthsWindow' => $months, // M5: the active trailing-window length, or null = default full-year tables
            'currentYearRows' => $currentYearRows,
            'priorYearRows'   => $priorYearRows,
            'summary' => $summary,
            // Which ad-platform share columns to show (Kanwar: "if tiktok is not
            // connected don't show tiktok"). A platform appears when it's an
            // active connection OR has real spend in the window — so a connected
            // platform shows even at 0% spend, and we never hide spend we do have.
            'adPlatforms' => $this->adPlatforms($brand->id, $byMonth),
            // Whether ANY goal target exists — the Goal column is hidden entirely
            // when the brand has no target set (Kanwar: "goal is empty don't show").
            'hasGoals' => $targets['default'] !== null || $targets['map'] !== [],
            // ROAS-nc is MODELED (new customers × blended AOV ÷ spend): Shopify
            // can't report sales by customer type, so new-customer revenue is an
            // estimate — same honesty basis as S2's new/returning split.
            'roasNcModeled' => true,
            // Only flag the customer columns as unavailable when we genuinely have
            // no counts (no Shopify connection / read_reports scope). When counts
            // ARE present, these columns are real and no note is shown.
            'unavailable' => $counts === [] ? [
                'customerColumns' => 'New / Returning / %Ret / Total customers / CAC / ROAS-nc need the live Shopify customer counts (ShopifyQL read_reports) — none available for this brand yet.',
            ] : [],
        ];
    }

    /**
     * A brand's revenue-goal targets as a per-month map plus the standing default,
     * so each matrix row can show its Goal % (actual ÷ target − 1) only where a
     * target exists — never a fabricated goal.
     *
     * @return array{map: array<string, float>, default: ?float}
     */
    private function targetsFor(int $brandId): array
    {
        $map = [];
        $default = null;
        foreach (BrandTarget::query()->where('brand_id', $brandId)->get() as $t) {
            if ($t->revenue_target === null) {
                continue;
            }
            if ($t->month === null) {
                $default = (float) $t->revenue_target;
            } else {
                $map[(string) $t->month] = (float) $t->revenue_target;
            }
        }

        return ['map' => $map, 'default' => $default];
    }

    /**
     * The ad platforms whose spend-share column should render: an active
     * connection OR any spend in the window. Keeps a connected-but-zero-spend
     * platform visible, hides a platform that is neither connected nor spending,
     * and never hides spend we actually have. Ordered google → meta → tiktok
     * (Google first, matching the reference).
     *
     * @param array<string, array{googleSpend:float, metaSpend:float, tiktokSpend:float}> $byMonth
     * @return array<int, string>
     */
    private function adPlatforms(int $brandId, array $byMonth): array
    {
        $connected = PlatformConnection::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', self::AD_PLATFORMS)
            ->where('status', 'active')
            ->pluck('platform')
            ->all();

        $spendKey = ['google' => 'googleSpend', 'meta' => 'metaSpend', 'tiktok' => 'tiktokSpend'];
        $out = [];
        foreach (['google', 'meta', 'tiktok'] as $platform) {
            $spend = 0.0;
            foreach ($byMonth as $m) {
                $spend += (float) ($m[$spendKey[$platform]] ?? 0);
            }
            if (in_array($platform, $connected, true) || $spend > 0.0) {
                $out[] = $platform;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{orders:int, revenue:float, refunds:float, spend:float, googleSpend:float}> $byMonth
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $byMonth, array $counts, array $targets, int $year, int $fromMonth, int $toMonth): array
    {
        $rows = [];
        for ($m = $fromMonth; $m <= $toMonth; $m++) {
            $rows[] = $this->rowFor($byMonth, $counts, $targets, CarbonImmutable::createFromDate($year, $m, 1));
        }

        return $rows;
    }

    /**
     * M5 addendum — `count` months ending at (and including) `endMonth`,
     * oldest-first, crossing a calendar-year boundary freely (unlike
     * buildRows, which is always a single-year Jan..N slice). Each row uses
     * the exact same per-row shape/math as buildRows via the shared rowFor().
     *
     * @param array<string, array{orders:int, revenue:float, refunds:float, spend:float, googleSpend:float}> $byMonth
     * @return array<int, array<string, mixed>>
     */
    private function buildTrailingRows(array $byMonth, array $counts, array $targets, CarbonImmutable $endMonth, int $count): array
    {
        $rows = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $rows[] = $this->rowFor($byMonth, $counts, $targets, $endMonth->subMonths($i)->startOfMonth());
        }

        return $rows;
    }

    /**
     * @param array<string, array{orders:int, revenue:float, refunds:float, spend:float, googleSpend:float, metaSpend:float, tiktokSpend:float}> $byMonth
     * @param array<string, array{customers:int, returning:int, new:int, orders:int, newPct:float, retPct:float}> $counts
     * @param array{map: array<string, float>, default: ?float} $targets
     */
    private function rowFor(array $byMonth, array $counts, array $targets, CarbonImmutable $monthDate): array
    {
        $key = $monthDate->format('Y-m');
        $cur = $byMonth[$key] ?? null;

        $prevKey = $monthDate->subMonth()->format('Y-m');
        $prev    = $byMonth[$prevKey] ?? null;

        if ($cur === null) {
            return ['month' => $key, 'label' => $monthDate->isoFormat('MMMM YYYY'), 'status' => 'no_data'];
        }

        $spend = $cur['spend'];
        $roas = $spend > 0.0 ? round($cur['revenue'] / $spend, 2) : null;
        $prevRoas = ($prev !== null && $prev['spend'] > 0.0) ? round($prev['revenue'] / $prev['spend'], 2) : null;
        $aov  = $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 2) : null;

        // Customer split for this month (live Shopify counts) — null throughout
        // when unavailable, so the columns render "—" rather than a fake 0.
        $c     = $counts[$key] ?? null;
        $cPrev = $counts[$prevKey] ?? null; // prior month, for the MoM customer deltas
        $new       = $c['new'] ?? null;
        $returning = $c['returning'] ?? null;
        $totalCust = $c['customers'] ?? null;
        $retPct    = $c['retPct'] ?? null;

        // CAC = spend ÷ new customers; ROAS-nc = MODELED new revenue (new × blended
        // AOV) ÷ spend — the exact v1 monthly-report estimate (MonthlyReport).
        $cac    = ($new !== null && $new > 0 && $spend > 0.0) ? round($spend / $new, 2) : null;
        $roasNc = ($new !== null && $aov !== null && $spend > 0.0) ? round(($new * $aov) / $spend, 2) : null;

        // Goal % = actual revenue ÷ target − 1 (over/under the goal), only where a
        // target exists (explicit month target wins, else the standing default).
        $target = $targets['map'][$key] ?? $targets['default'] ?? null;
        $goalPct = ($target !== null && $target > 0.0) ? round(($cur['revenue'] / $target - 1) * 100, 1) : null;

        return [
            'month'  => $key,
            'label'  => $monthDate->isoFormat('MMMM YYYY'),
            'status' => 'ok',
            'orders' => $cur['orders'],
            'aov'    => $aov,
            'returnsPct' => $cur['revenue'] > 0.0 ? round(abs($cur['refunds']) / $cur['revenue'] * 100, 1) : null,
            'revenue' => round($cur['revenue'], 2),
            'spend'   => round($spend, 2),
            'googleSharePct' => $spend > 0.0 ? round($cur['googleSpend'] / $spend * 100, 1) : null,
            'metaSharePct'   => $spend > 0.0 ? round($cur['metaSpend'] / $spend * 100, 1) : null,
            'tiktokSharePct' => $spend > 0.0 ? round($cur['tiktokSpend'] / $spend * 100, 1) : null,
            'roas'    => $roas,
            // Customer-split columns (real counts; null = unavailable, not zero).
            'new'            => $new,
            'returning'      => $returning,
            'retPctCustomers' => $retPct,
            'totalCustomers' => $totalCust,
            'cac'            => $cac,
            'roasNc'         => $roasNc, // MODELED (see roasNcModeled flag on the payload)
            'goalPct'        => $goalPct,
            // Comparison columns — MONTH-OVER-MONTH (Kanwar, 2026-07-15: "month vs
            // previous month so we see progress"). Captación = new-customer MoM,
            // Ret Δ = returning-customer MoM; Δ Revenue / Δ Budget reuse the
            // revenue/spend MoM deltas below.
            'captacionMoMPct' => ($new !== null && $cPrev !== null && ($cPrev['new'] ?? 0) > 0) ? $this->pctDelta((float) $new, (float) $cPrev['new']) : null,
            'retentionMoMPct' => ($returning !== null && $cPrev !== null && ($cPrev['returning'] ?? 0) > 0) ? $this->pctDelta((float) $returning, (float) $cPrev['returning']) : null,
            'deltaRevenuePct' => $prev !== null ? $this->pctDelta($cur['revenue'], $prev['revenue']) : null,
            'deltaSpendPct'   => $prev !== null ? $this->pctDelta($cur['spend'], $prev['spend']) : null,
            'deltaRoasPct'    => $this->pctDelta($roas, $prevRoas),
            'revenueFlag' => $this->flag($cur['revenue'], $prev['revenue'] ?? null),
            'roasFlag'    => $this->flag($roas, $prevRoas),
        ];
    }

    /** Collapse S1 to headline financials over the custom range vs the same range last year. */
    private function rangeCollapse(Brand $brand, ReportFilters $filters, string $tz): array
    {
        $range = $filters->activeWindow($tz);
        $cmp   = $filters->activeComparisonWindow($tz);
        if ($range === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }

        $cur = $this->rangeAggregate($brand->id, $range[0], $range[1]);
        if ($cur['revenue'] === 0.0 && $cur['spend'] === 0.0 && $cur['orders'] === 0) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No Shopify/ad data in the selected range.'];
        }
        $prev = $cmp !== null ? $this->rangeAggregate($brand->id, $cmp[0], $cmp[1]) : null;

        $roas     = $cur['spend'] > 0.0 ? round($cur['revenue'] / $cur['spend'], 2) : null;
        $prevRoas = ($prev !== null && $prev['spend'] > 0.0) ? round($prev['revenue'] / $prev['spend'], 2) : null;
        $aov      = $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 2) : null;
        $prevAov  = ($prev !== null && $prev['orders'] > 0) ? round($prev['revenue'] / $prev['orders'], 2) : null;

        $metric = static fn (string $label, float|int|null $v, float|int|null $p, string $f): array => [
            RangeCollapse::cell($label, 'text'),
            RangeCollapse::cell($v, $f),
            RangeCollapse::cell($p, $f),
            RangeCollapse::cell(RangeCollapse::delta($v, $p), 'delta'),
        ];

        $rangeLabel   = RangeCollapse::label($range[0], $range[1]);
        $compareLabel = $cmp !== null ? RangeCollapse::label($cmp[0], $cmp[1]) : 'Last year';
        $rows = [
            $metric('Revenue',  round($cur['revenue'], 2), $prev !== null ? round($prev['revenue'], 2) : null, 'money'),
            $metric('Ad spend', round($cur['spend'], 2),   $prev !== null ? round($prev['spend'], 2) : null,   'money'),
            $metric('Blended ROAS', $roas, $prevRoas, 'ratio'),
            $metric('Orders',   $cur['orders'], $prev !== null ? $prev['orders'] : null, 'count'),
            $metric('AOV',      $aov, $prevAov, 'money'),
        ];

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'range'  => true,
            'rangeCollapse' => RangeCollapse::table(
                $rangeLabel,
                $compareLabel,
                ['Metric', $rangeLabel, $compareLabel, 'Δ YoY'],
                $rows,
                null,
                'New / Returning / CAC columns need whole calendar months and show in month mode.',
            ),
        ];
    }

    /** @return array{revenue: float, orders: int, spend: float} range totals (D-005 revenue, all ad platforms). */
    private function rangeAggregate(int $brandId, string $start, string $end): array
    {
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005

        $shop = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("COALESCE(SUM({$revCol}), 0) AS revenue, COALESCE(SUM(orders), 0) AS orders")
            ->first();

        $spend = (float) DailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', self::AD_PLATFORMS)
            ->whereBetween('date', [$start, $end])
            ->sum('spend');

        return [
            'revenue' => (float) ($shop->revenue ?? 0),
            'orders'  => (int) ($shop->orders ?? 0),
            'spend'   => $spend,
        ];
    }

    /** @return array<string, array{orders:int, revenue:float, refunds:float, spend:float, googleSpend:float, metaSpend:float, tiktokSpend:float}> */
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

        $zero = ['orders' => 0, 'revenue' => 0.0, 'refunds' => 0.0, 'spend' => 0.0, 'googleSpend' => 0.0, 'metaSpend' => 0.0, 'tiktokSpend' => 0.0];

        $out = [];
        foreach ($shopifyRows as $r) {
            $key = CarbonImmutable::parse((string) $r->date)->format('Y-m');
            $out[$key] ??= $zero;
            $out[$key]['orders']  += (int) $r->orders;
            $out[$key]['revenue'] += (float) $r->revenue;
            $out[$key]['refunds'] += (float) $r->refunds;
        }
        foreach ($adRows as $r) {
            $key = CarbonImmutable::parse((string) $r->date)->format('Y-m');
            $out[$key] ??= $zero;
            $spend = (float) $r->spend;
            $out[$key]['spend'] += $spend;
            if ($r->platform === 'google') {
                $out[$key]['googleSpend'] += $spend;
            } elseif ($r->platform === 'meta') {
                $out[$key]['metaSpend'] += $spend;
            } elseif ($r->platform === 'tiktok') {
                $out[$key]['tiktokSpend'] += $spend;
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
