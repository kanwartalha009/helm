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
 * Also carries a MODELED new-vs-returning sales split (Kanwar, 2026-07-15):
 * Shopify's ShopifyQL `sales` dataset has NO customer_type dimension, so
 * revenue genuinely cannot be split by new vs returning customer — only the
 * monthly customer COUNTS exist (CustomerMix). We therefore ESTIMATE it with
 * the SAME method v1's monthly report already uses (MonthlyReport::
 * newVsExistingSection): new-customer sales ≈ new customers × blended AOV
 * (revenue ÷ orders); returning sales = total − new so the split always
 * reconciles to the real total. It uses blended AOV, so — exactly as v1's own
 * footnote says — it runs slightly HIGH for new customers (whose first order is
 * usually below the blended average). Labelled `basis: 'modeled'` with the
 * method spelled out, never Verified (the "Modeled — baseline" law, §0). Null
 * (omitted) when the split isn't available (no Shopify connection / no orders).
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
        $window = $filters->monthWindow($tz);
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

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $this->dailyRevenue($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $total = round(array_sum(array_column($cur, 'revenue')), 2);

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
            // MODELED new-vs-returning sales split — see the class docblock.
            'customerSalesSplit' => $this->modeledCustomerSplit($brand, $start, $end, $total),
        ];
    }

    /**
     * ESTIMATE the month's new- vs returning-customer sales, the same way v1's
     * monthly report does: new sales ≈ new customers × blended AOV (revenue ÷
     * orders); returning sales = total − new (so the two reconcile to the real
     * total). new × AOV can never exceed the total (new ≤ customers ≤ orders, so
     * new × AOV ≤ orders × AOV = revenue), so returning is always ≥ 0. Null when
     * the customer counts or orders aren't available.
     *
     * @return array<string, mixed>|null
     */
    private function modeledCustomerSplit(Brand $brand, string $start, string $end, float $total): ?array
    {
        $mix = $this->customerMix->forMonth($brand, $start, $end);
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
            'method' => 'Estimate — new-customer sales ≈ new customers × blended AOV (revenue ÷ orders); returning = total − new. Shopify doesn’t report sales by customer type, and blended AOV runs slightly high for new customers.',
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
