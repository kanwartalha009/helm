<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\ProductCatalog;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\RangeCollapse;
use App\Reports\Mom\Support\WeekSplit;
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

        // Custom range (Kanwar, 2026-07-20): week-on-week product revenue — one
        // column per ISO week across the range (running month included).
        if ($filters->isCustomRange()) {
            return $this->weekly($brand, $filters, $tz, 'product', 10, 'Week-on-week revenue by product');
        }

        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }

        // Month-by-month matrix (Kanwar, 2026-07-16): product × the last N months
        // (window control) — the "last-6-months" trend the original pass deferred,
        // now real — with per-month revenue + Total/Share/ΔMoM/ΔYoY + stock.
        $reportMonth = CarbonImmutable::parse($window[0], $tz)->startOfMonth();
        $n = $filters->months === null ? 6 : max(1, min(12, $filters->months));
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = $reportMonth->subMonths($i)->format('Y-m');
        }

        $breakdown = (new CommerceBreakdown())->monthlyMatrix($brand->id, 'product', $months, $filters->usd, 10);
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
            'month'  => $reportMonth->format('Y-m'),
            'months' => $months,
            'monthLabels' => array_map(static fn (string $ym): string => CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->isoFormat('MMM YY'), $months),
            'monthsWindow' => $n,
            'rows'   => $rows,
            'other'  => $breakdown['other'],
            'total'  => $breakdown['total'],
            'unavailable' => [],
        ];
    }

    /** Collapse S8 to product revenue over the custom range vs the same range last year. */
    /**
     * Week-on-week revenue by product across the custom range — the displayed
     * groups are the range's top-N (plus an Other tail); each week's per-key
     * revenue is pulled with a wide limit so a product that isn't top-N in a
     * given week still lands in its own cell rather than hiding inside Other.
     */
    private function weekly(Brand $brand, ReportFilters $filters, string $tz, string $dimension, int $limit, string $title): array
    {
        $range = $filters->activeWindow($tz);
        if ($range === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }
        $weeks = WeekSplit::windows($range[0], $range[1], $tz);
        if ($weeks === []) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'Pick a start and end date.'];
        }

        $bd = new CommerceBreakdown();
        $rangeBd = $bd->forDimension($brand->id, $dimension, $range[0], $range[1], null, null, $filters->usd, $limit);
        if ($rangeBd === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No commerce data in the selected range.'];
        }

        $perWeekMap = [];
        $perWeekTotal = [];
        foreach ($weeks as $i => $w) {
            $wb = $bd->forDimension($brand->id, $dimension, $w['start'], $w['end'], null, null, $filters->usd, 500);
            $map = [];
            foreach (($wb['rows'] ?? []) as $r) {
                if (isset($r['key'])) {
                    $map[$r['key']] = (float) $r['revenue'];
                }
            }
            $perWeekMap[$i] = $map;
            $perWeekTotal[$i] = (float) ($wb['total']['revenue'] ?? 0.0);
        }

        $topKeys = [];
        foreach ($rangeBd['rows'] as $r) {
            if (isset($r['key'])) {
                $topKeys[] = $r['key'];
            }
        }

        $groups = [];
        foreach ($rangeBd['rows'] as $r) {
            $cells = [];
            if (isset($r['key'])) {
                foreach ($weeks as $i => $_) {
                    $cells[] = array_key_exists($r['key'], $perWeekMap[$i]) ? round($perWeekMap[$i][$r['key']], 2) : null;
                }
            } else {
                foreach ($weeks as $i => $_) {
                    $sumTop = 0.0;
                    foreach ($topKeys as $k) {
                        $sumTop += $perWeekMap[$i][$k] ?? 0.0;
                    }
                    $other = round($perWeekTotal[$i] - $sumTop, 2);
                    $cells[] = $other > 0.009 ? $other : null;
                }
            }
            $groups[] = ['label' => $r['label'], 'weekly' => $cells, 'total' => round((float) $r['revenue'], 2)];
        }

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'range'  => true,
            'rangeCollapse' => RangeCollapse::weeklyRevenueByGroup(
                'Segment',
                array_column($weeks, 'label'),
                $groups,
                $title,
                WeekSplit::note($weeks),
            ),
        ];
    }
}
