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
        // No week-on-week view for this section yet (its landing-spend-vs-sellers
        // matrix is built per whole month), so hide the card in custom-range mode
        // rather than show "No complete month selected" (Kanwar, 2026-07-22).
        // `hidden` makes MomSectionCard render nothing.
        if ($filters->isCustomRange()) {
            return ['key' => $this->key(), 'status' => 'no_data', 'hidden' => true];
        }

        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }

        // Month-by-month matrix (Kanwar, 2026-07-16): product × the last N months
        // (window control) of LANDING SPEND, + window spend total / revenue / stock
        // / ΔMoM / ΔYoY. Revenue + stock stay window-level (the spend-vs-seller
        // comparison and the mismatch flag are about the whole window).
        $reportMonth = CarbonImmutable::parse($window[0], $tz)->startOfMonth();
        $n = $filters->months === null ? 6 : max(1, min(12, $filters->months));
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = $reportMonth->subMonths($i)->format('Y-m');
        }
        [$start, $end] = [CarbonImmutable::parse($months[0] . '-01')->startOfMonth()->toDateString(), $reportMonth->endOfMonth()->toDateString()];

        // Per-(product, month) landing spend, bucketed in PHP (driver-agnostic).
        $spendDaily = AdProductDaily::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$start, $end])
            ->groupBy('product_key', 'date')
            ->selectRaw('product_key, date, COALESCE(SUM(spend), 0) AS spend')
            ->get();

        $spendByKeyMonth = []; // key => [ym => spend]
        $windowSpend = [];     // key => total
        foreach ($spendDaily as $r) {
            $ym = CarbonImmutable::parse((string) $r->date)->format('Y-m');
            $key = (string) $r->product_key;
            $spendByKeyMonth[$key][$ym] = ($spendByKeyMonth[$key][$ym] ?? 0.0) + (float) $r->spend;
            $windowSpend[$key] = ($windowSpend[$key] ?? 0.0) + (float) $r->spend;
        }

        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005
        $revenueRows = CommerceDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('dimension_type', 'product')
            ->whereBetween('date', [$start, $end])
            ->groupBy('dimension_key')
            ->selectRaw("dimension_key, COALESCE(SUM({$revCol}), 0) AS revenue")
            ->get();

        if ($spendByKeyMonth === [] && $revenueRows->isEmpty()) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No ad_product_daily or commerce-by-product data synced for this brand/month yet.',
            ];
        }

        // Prior-year window landing spend per product, for ΔYoY.
        $priorSpend = AdProductDaily::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$reportMonth->subMonths($n - 1)->subYear()->startOfMonth()->toDateString(), $reportMonth->subYear()->endOfMonth()->toDateString()])
            ->groupBy('product_key')
            ->selectRaw('product_key, COALESCE(SUM(spend), 0) AS spend')
            ->pluck('spend', 'product_key');

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
        foreach ($windowSpend as $productKey => $spendTotal) {
            $handleKey = mb_strtolower((string) $productKey);
            $isReserved = in_array($productKey, ['__collection', '__other'], true);
            $title = $isReserved ? null : ($titleByHandle[$handleKey] ?? null);
            $revenue = $title !== null ? ($revenueByTitle[mb_strtolower($title)] ?? null) : null;
            $stock = $title !== null ? ($stockByTitle[mb_strtolower($title)] ?? null) : null;

            $monthly = [];
            foreach ($months as $ym) {
                $monthly[] = isset($spendByKeyMonth[$productKey][$ym]) ? round((float) $spendByKeyMonth[$productKey][$ym], 2) : null;
            }
            $lastM = $monthly[$n - 1] ?? null;
            $prevM = $n >= 2 ? ($monthly[$n - 2] ?? null) : null;

            $row = [
                'handle'  => (string) $productKey,
                'title'   => $title,
                'monthly' => $monthly,
                'spend'   => round((float) $spendTotal, 2),
                'revenue' => $revenue !== null ? round($revenue, 2) : null,
                'stock'   => $stock,
                'unattributed' => $isReserved,
                'deltaMoMPct' => $this->delta($lastM, $prevM),
                'deltaYoYPct' => $this->delta((float) $spendTotal, isset($priorSpend[$productKey]) ? (float) $priorSpend[$productKey] : null),
            ];
            $rows[] = $row;

            if (! $isReserved && ($topSpend === null || $row['spend'] > $topSpend['spend'])) {
                $topSpend = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

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
            'month'  => $reportMonth->format('Y-m'),
            'months' => $months,
            'monthLabels' => array_map(static fn (string $ym): string => CarbonImmutable::createFromFormat('Y-m-d', $ym . '-01')->isoFormat('MMM YY'), $months),
            'monthsWindow' => $n,
            'rows'   => $rows,
            'mismatch' => $mismatch,
            'unavailable' => [
                'unmatchedHandles' => 'A handle with no matching product_catalog row (deleted/renamed product) shows title=null — spend is still shown, never dropped.',
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
