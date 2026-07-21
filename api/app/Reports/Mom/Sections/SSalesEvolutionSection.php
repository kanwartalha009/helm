<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CustomerMix;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S2 Total sales evolution. Daily revenue
 * line for the report month with prior-year same-month overlay (both from
 * daily_metrics; plain SVG/div chart per report conventions)."
 *
 * Uses the report-wide comparison filter (REV2 R3) for the overlay window
 * rather than hardcoding "prior year" — consistent with every other section
 * in this program; a client can still pick 'Same month last year' as the
 * report's compare mode to get the PDF's exact traditional view.
 *
 * Also carries a new-vs-returning sales split. This is now VERIFIED where
 * possible (Kanwar, 2026-07-21): Shopify's ShopifyQL `sales` dataset DOES expose
 * revenue by customer type — the dimension is `new_or_returning_customer`
 * (values "New" / "Returning"), verified live via shopify:diagnose-customer-type.
 * Our earlier "no such dimension" conclusion was wrong: the first probe bundled
 * an unproven metric (`units_sold`) into every query, so a rejected metric
 * looked like a rejected dimension. We now pull Shopify's OWN net/total sales per
 * type (basis: 'verified') — the exact figures the merchant sees in Analytics >
 * Reports.
 *
 * The legacy ESTIMATE (basis: 'modeled') is kept ONLY as a fallback for when the
 * live split can't be fetched (no Shopify connection / missing read_reports +
 * read_customers scope / transport failure): new-customer sales ≈ new customers ×
 * blended AOV (revenue ÷ orders); returning = total − new. It runs slightly HIGH
 * for new customers (first orders usually below the blended average) and is
 * always labelled as an estimate, never Verified (the "Modeled — baseline" law,
 * §0). Null (omitted) only when neither path has data.
 */
final class SSalesEvolutionSection implements MomSection
{
    public function __construct(private readonly CustomerMix $customerMix)
    {
    }

    public function key(): string
    {
        return 'S2';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->activeWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->dailyRevenue($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Shopify daily data synced for this brand/month yet.',
            ];
        }

        $compareWindow = $filters->activeComparisonWindow($tz);
        $cmp = $compareWindow !== null ? $this->dailyRevenue($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $total = round(array_sum(array_column($cur, 'revenue')), 2);
        $split = $this->customerSalesSplit($brand, $start, $end, $total);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'currency' => $brand->base_currency ?: 'USD',
            'series'  => $cur,
            'compareSeries' => $cmp,
            'total'   => $total,
            'compareTotal' => $cmp !== null ? round(array_sum(array_column($cmp, 'revenue')), 2) : null,
            // MODELED new-vs-returning sales split for THIS month (headline amounts).
            'customerSalesSplit' => $split,
            // MODELED new-vs-returning sales as a DAILY series across the month —
            // same x-axis (days) as the sales line above it. Each day's real
            // revenue is allocated by the MONTH's new/returning share (we have no
            // daily customer-type data), so it sums to the monthly split.
            'customerSalesDaily' => $this->modeledCustomerDaily($cur, $split),
        ];
    }

    /**
     * The month's new- vs returning-customer sales split. VERIFIED first (Kanwar,
     * 2026-07-21 — client wanted actual numbers): Shopify's `sales` schema exposes
     * real revenue by customer type under the `new_or_returning_customer`
     * dimension, so we pull Shopify's OWN net/total sales per type — the exact
     * figures the merchant sees in Analytics > Reports. Customer COUNTS come from
     * the same CustomerMix source they always did (also real), and the % shown
     * beside each amount is that amount's SHARE OF SALES (not the count share), so
     * it reads correctly under a revenue figure.
     *
     * Falls back to the legacy ESTIMATE only when the live split isn't available
     * (no Shopify connection / missing read_reports+read_customers scope / any
     * transport or parse failure): new sales ≈ new customers × blended AOV
     * (revenue ÷ orders); returning = total − new. That path is clearly labelled
     * basis:'modeled' with the method spelled out, per the "Modeled — baseline"
     * law (§0). Null (section omits the split) when neither path has data.
     *
     * @return array<string, mixed>|null
     */
    private function customerSalesSplit(Brand $brand, string $start, string $end, float $total): ?array
    {
        $mix = $this->customerMix->forMonth($brand, $start, $end);

        // ── Preferred: Shopify's REAL revenue split by customer type.
        $real = $this->customerMix->salesSplitForMonth($brand, $start, $end);
        if ($real !== null) {
            // Display total_sales — the figure the client checks against in Shopify
            // Analytics (net_sales is carried too for anyone who needs after-returns).
            $newSales = round((float) $real['new']['total'], 2);
            $retSales = round((float) $real['returning']['total'], 2);
            $splitTotal = $newSales + $retSales;

            if ($splitTotal > 0.0) {
                return [
                    'basis'  => 'verified',
                    'method' => 'Actual — Shopify net/total sales by New vs Returning customer (Analytics “New or returning customer” dimension). % is each type’s share of sales.',
                    'new' => [
                        'customers' => $mix['new'] ?? null,
                        'sales'     => $newSales,
                        'net'       => round((float) $real['new']['net'], 2),
                        'pct'       => round($newSales / $splitTotal * 100, 1),
                    ],
                    'returning' => [
                        'customers' => $mix['returning'] ?? null,
                        'sales'     => $retSales,
                        'net'       => round((float) $real['returning']['net'], 2),
                        'pct'       => round($retSales / $splitTotal * 100, 1),
                    ],
                ];
            }
        }

        // ── Fallback: the legacy estimate. Requires counts + orders + revenue.
        if ($mix === null) {
            return null;
        }

        $orders = (int) DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->sum('orders');

        if ($orders <= 0 || $total <= 0.0) {
            return null; // no AOV to model from — honest omission, not a fake split
        }

        $aov      = $total / $orders;
        $newSales = round(min($mix['new'] * $aov, $total), 2);
        $retSales = round($total - $newSales, 2);

        return [
            'basis'  => 'modeled',
            'method' => 'Estimate — new-customer sales ≈ new customers × blended AOV (revenue ÷ orders); returning = total − new. Shopify’s live customer-type split was unavailable, and blended AOV runs slightly high for new customers.',
            'aov'    => round($aov, 2),
            'new' => [
                'customers' => $mix['new'],
                'sales'     => $newSales,
                'pct'       => $mix['newPct'],
            ],
            'returning' => [
                'customers' => $mix['returning'],
                'sales'     => $retSales,
                'pct'       => $mix['retPct'],
            ],
        ];
    }

    /**
     * MODELED new-vs-returning sales as a DAILY series across the report month —
     * so the graph shares the SAME x-axis (days of the month) as the total-sales
     * line above it. We have no daily customer-type data (Shopify only reports
     * monthly counts), so each day's REAL revenue is allocated by the MONTH's
     * modeled new-share (from `$split`): new_day = day_rev × newShare, returning
     * = day_rev − new_day. The daily values therefore sum exactly to the monthly
     * split. Null when the month split isn't available.
     *
     * @param array<int, array{day: int, revenue: float}> $daily
     * @param array<string, mixed>|null $split  the month's modeledCustomerSplit
     * @return array<int, array{day: int, new: float, returning: float}>|null
     */
    private function modeledCustomerDaily(array $daily, ?array $split): ?array
    {
        if ($split === null) {
            return null;
        }

        $newMonth = (float) $split['new']['sales'];
        $retMonth = (float) $split['returning']['sales'];
        $monthTotal = $newMonth + $retMonth;
        if ($monthTotal <= 0.0) {
            return null;
        }
        $newShare = $newMonth / $monthTotal;

        return array_map(static function (array $d) use ($newShare): array {
            $rev = (float) $d['revenue'];
            $new = round($rev * $newShare, 2);

            return [
                'day'       => (int) $d['day'],
                'new'       => $new,
                'returning' => round($rev - $new, 2),
            ];
        }, $daily);
    }

    /** @return array<int, array{day: int, revenue: float}>|null */
    private function dailyRevenue(int $brandId, string $start, string $end): ?array
    {
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005

        $rows = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->selectRaw("date, COALESCE(SUM({$revCol}), 0) AS revenue")
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(static fn ($r): array => [
            'day'     => (int) CarbonImmutable::parse((string) $r->date)->format('j'),
            'revenue' => round((float) $r->revenue, 2),
        ])->values()->all();
    }
}
