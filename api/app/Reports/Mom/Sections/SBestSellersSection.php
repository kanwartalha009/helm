<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\ProductCatalog;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Support\CommerceBreakdown;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S8 Best sellers MoM (slides 17-18) —
 * product x month revenue + Last-6-months + share + STOCK column (catalog
 * join, red when low/0) + YoY."
 *
 * Reuses `CommerceBreakdown` (dimension_type='product', keyed on product
 * TITLE) for the ranking/trend, same as S7. The stock join uses
 * `product_catalog.title` — that table's own docblock confirms it's the
 * bridge that lets ad spend (keyed by handle) and commerce (keyed by title)
 * both resolve to one product record; matched case-insensitively since
 * `product_catalog.title` isn't normalised the way `handle` is.
 *
 * "Last-6-months" (a 6-month trend column, not just base+compare) is NOT
 * built this pass — `CommerceBreakdown::forDimension()` only returns one
 * comparison window, not a rolling multi-month series; logged unavailable
 * rather than approximated from two points.
 */
final class SBestSellersSection implements MomSection
{
    private const LOW_STOCK_FLOOR = 5;

    public function key(): string
    {
        return 'S8';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $compareWindow = $filters->compareMonthWindow($tz);

        $breakdown = (new CommerceBreakdown())->forDimension(
            $brand->id, 'product', $start, $end,
            $compareWindow[0] ?? null, $compareWindow[1] ?? null,
            $filters->usd,
            10,
        );

        if ($breakdown === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No commerce-by-product data synced for this brand/month yet (shopify:backfill-commerce).',
            ];
        }

        $stockByTitle = ProductCatalog::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('title')
            ->get(['title', 'total_inventory'])
            ->mapWithKeys(static fn ($p) => [mb_strtolower((string) $p->title) => (int) $p->total_inventory]);

        $rows = array_map(function (array $row) use ($stockByTitle): array {
            $stock = $stockByTitle[mb_strtolower($row['label'])] ?? null;
            $row['stock'] = $stock;
            $row['stockFlag'] = $stock === null ? null : ($stock <= self::LOW_STOCK_FLOOR ? 'red' : null);

            return $row;
        }, $breakdown['rows']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'rows'   => $rows,
            'other'  => $breakdown['other'],
            'total'  => $breakdown['total'],
            'unavailable' => [
                'last6Months' => 'Needs a rolling multi-month series — CommerceBreakdown only returns one comparison window, not built this pass.',
            ],
        ];
    }
}
