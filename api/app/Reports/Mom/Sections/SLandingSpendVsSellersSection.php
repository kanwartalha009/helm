<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\AdProductDaily;
use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\ProductCatalog;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M3 (monthly-report-v2-mom.md §M3) — "S17 Landing spend x best sellers
 * (slides 19-20) — ad_product_daily spend by product landing vs product
 * revenue + stock — both already synced; flags mismatches ('spending on X,
 * best seller is Y')."
 *
 * REVISITED from M3's original pass, which left this unregistered because the
 * `ad_product_daily` (keyed by product HANDLE) <-> `CommerceDailyMetric`
 * (keyed by product TITLE) join wasn't verified. `product_catalog` — found
 * during the M2-continuation pass — is confirmed (by its own migration
 * docblock) to be exactly that bridge: one row per (brand, handle) carrying
 * both `handle` and `title`. This section joins through it.
 *
 * `ad_product_daily`'s reserved keys `__collection` and `__other` (spend that
 * isn't attributable to one product) are kept as their own rows, never folded
 * into a real product's spend and never silently dropped — they're exactly
 * the "not product-specific" spend that table's own docblock calls out.
 *
 * Mismatch flag: the single highest-SPEND product is compared against the
 * single highest-REVENUE product; if they differ, both are named in
 * `mismatch` — mirrors the PDF's own "spending on X, best seller is Y" framing
 * rather than a fuzzy multi-row heuristic.
 */
final class SLandingSpendVsSellersSection implements MomSection
{
    public function key(): string
    {
        return 'S17';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $spendRows = AdProductDaily::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$start, $end])
            ->groupBy('product_key')
            ->selectRaw('product_key, COALESCE(SUM(spend), 0) AS spend')
            ->get();

        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005
        $revenueRows = CommerceDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('dimension_type', 'product')
            ->whereBetween('date', [$start, $end])
            ->groupBy('dimension_key')
            ->selectRaw("dimension_key, COALESCE(SUM({$revCol}), 0) AS revenue")
            ->get();

        if ($spendRows->isEmpty() && $revenueRows->isEmpty()) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No ad_product_daily or commerce-by-product data synced for this brand/month yet.',
            ];
        }

        $bridge = ProductCatalog::query()
            ->where('brand_id', $brand->id)
            ->get(['handle', 'title', 'total_inventory'])
            ->keyBy(static fn ($p) => mb_strtolower((string) $p->handle));

        $titleByHandle = $bridge->mapWithKeys(static fn ($p) => [mb_strtolower((string) $p->handle) => (string) $p->title]);
        $stockByTitle  = $bridge->mapWithKeys(static fn ($p) => [mb_strtolower((string) $p->title) => (int) $p->total_inventory]);

        $revenueByTitle = [];
        foreach ($revenueRows as $r) {
            $revenueByTitle[mb_strtolower((string) $r->dimension_key)] = (float) $r->revenue;
        }

        $rows = [];
        $topSpend = null;
        foreach ($spendRows as $r) {
            $handleKey = mb_strtolower((string) $r->product_key);
            $isReserved = in_array($r->product_key, ['__collection', '__other'], true);
            $title = $isReserved ? null : ($titleByHandle[$handleKey] ?? null);
            $revenue = $title !== null ? ($revenueByTitle[mb_strtolower($title)] ?? null) : null;
            $stock = $title !== null ? ($stockByTitle[mb_strtolower($title)] ?? null) : null;

            $row = [
                'handle'  => (string) $r->product_key,
                'title'   => $title,
                'spend'   => round((float) $r->spend, 2),
                'revenue' => $revenue !== null ? round($revenue, 2) : null,
                'stock'   => $stock,
                'unattributed' => $isReserved,
            ];
            $rows[] = $row;

            if (! $isReserved && ($topSpend === null || $row['spend'] > $topSpend['spend'])) {
                $topSpend = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): float => $b['spend'] <=> $a['spend']);

        $topRevenueTitle = null;
        $topRevenueValue = 0.0;
        foreach ($revenueByTitle as $title => $revenue) {
            if ($revenue > $topRevenueValue) {
                $topRevenueValue = $revenue;
                $topRevenueTitle = $title;
            }
        }

        $mismatch = null;
        if ($topSpend !== null && $topSpend['title'] !== null && $topRevenueTitle !== null
            && mb_strtolower($topSpend['title']) !== $topRevenueTitle) {
            $mismatch = [
                'spendingOn' => $topSpend['title'],
                'bestSeller' => $revenueRows->first(static fn ($r) => mb_strtolower((string) $r->dimension_key) === $topRevenueTitle)?->dimension_key ?? $topRevenueTitle,
            ];
        }

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'rows'   => $rows,
            'mismatch' => $mismatch,
            'unavailable' => [
                'unmatchedHandles' => 'A handle with no matching product_catalog row (deleted/renamed product) shows title=null — spend is still shown, never dropped.',
            ],
        ];
    }
}
