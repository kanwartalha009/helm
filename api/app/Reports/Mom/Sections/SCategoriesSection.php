<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\ProductCatalog;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\RangeCollapse;
use App\Reports\Support\CommerceBreakdown;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S7 Best categories MoM/YoY (slides
 * 15-16) — commerce category dimension, month columns + YoY deltas + share %;
 * stock question chip when a top category's products show low cover
 * (inventory join)."
 *
 * Reuses `App\Reports\Support\CommerceBreakdown` (already shared across
 * report types — v1's Country/Product reports and this program's own docblock
 * both point to it as the single source for this exact top-N + trajectory
 * shape) rather than reimplementing the ranking/trend logic a third time.
 *
 * Stock chip: `product_catalog.product_type` (verified against its migration)
 * IS the category dimension's key, so it's summed per category and flagged
 * `lowStock` when total inventory across that category's products is at or
 * below a small fixed floor. This is a PRESENCE check (does this category
 * have meaningfully non-zero stock on file), not a real "weeks of cover"
 * figure — that needs sell-through velocity math this pass doesn't compute —
 * labelled as such, not oversold as more than it is.
 */
final class SCategoriesSection implements MomSection
{
    private const LOW_STOCK_FLOOR = 20;
    public function key(): string
    {
        return 'S7';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';

        // Custom range (Kanwar, 2026-07-17): collapse to category revenue over the
        // range vs the same range last year.
        if ($filters->isCustomRange()) {
            return $this->rangeCollapse($brand, $filters, $tz);
        }

        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }

        // Month-by-month matrix (Kanwar, 2026-07-16): category × the last N months
        // (window control), with per-month revenue + Total/Share/ΔMoM/ΔYoY.
        $reportMonth = CarbonImmutable::parse($window[0], $tz)->startOfMonth();
        $n = $filters->months === null ? 6 : max(1, min(12, $filters->months));
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = $reportMonth->subMonths($i)->format('Y-m');
        }

        $breakdown = (new CommerceBreakdown())->monthlyMatrix($brand->id, 'category', $months, $filters->usd, 8);
        if ($breakdown === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No commerce-by-category data synced for this brand/month yet (shopify:backfill-commerce).',
            ];
        }

        $stockByCategory = ProductCatalog::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('product_type')
            ->groupBy('product_type')
            ->selectRaw('product_type, COALESCE(SUM(total_inventory), 0) AS stock')
            ->pluck('stock', 'product_type');

        $rows = array_map(function (array $row) use ($stockByCategory): array {
            $stock = $stockByCategory[$row['label']] ?? $stockByCategory[$row['key']] ?? null;
            $row['stock'] = $stock !== null ? (int) $stock : null;
            $row['lowStock'] = $stock !== null ? $stock <= self::LOW_STOCK_FLOOR : null;

            return $row;
        }, $breakdown['rows']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => $reportMonth->format('Y-m'),
            'months' => $months,
            'monthLabels' => array_map(static fn (string $ym): string => CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->isoFormat('MMM YY'), $months),
            'monthsWindow' => $n,
            'rows'   => $rows,
            'other'  => $breakdown['other'],
            'total'  => $breakdown['total'],
            'unavailable' => [
                'stockCoverWeeks' => 'Stock is a simple on-hand PRESENCE check (<= 20 units flags lowStock), not a real weeks-of-cover figure — that needs sell-through velocity math not computed this pass.',
            ],
        ];
    }

    /** Collapse S7 to category revenue over the custom range vs the same range last year. */
    private function rangeCollapse(Brand $brand, ReportFilters $filters, string $tz): array
    {
        $range = $filters->activeWindow($tz);
        $cmp   = $filters->activeComparisonWindow($tz);
        if ($range === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }

        $bd = (new CommerceBreakdown())->forDimension(
            $brand->id,
            'category',
            $range[0],
            $range[1],
            $cmp[0] ?? null,
            $cmp[1] ?? null,
            $filters->usd,
            8,
        );
        if ($bd === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No commerce-by-category data in the selected range.'];
        }

        $groups = [];
        foreach ($bd['rows'] as $r) {
            $groups[] = ['label' => $r['label'], 'value' => $r['revenue'], 'compare' => $r['previous']];
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
}
