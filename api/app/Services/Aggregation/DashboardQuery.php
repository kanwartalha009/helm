<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Services\Aggregation\Concerns\ScopesBrandsByManager;
use Carbon\CarbonImmutable;

/**
 * Assembles the data the dashboard table reads. Phase 1: Shopify revenue only
 * (Meta/Google/TikTok columns stay null — the frontend renders "N/A").
 *
 * Each row reports yesterday's and the day-before's revenue_net plus a
 * rolling 7-day sum for that brand. ROAS stays null until ad spend lands.
 */
final class DashboardQuery
{
    use ScopesBrandsByManager;

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function run(array $params): array
    {
        // Pull alphabetically for deterministic tie-breaks. The final order
        // the dashboard renders is set by the revenue sort at the bottom of
        // this method — best-performing brands first, zero/missing last.
        $brandQuery = Brand::query()->where('status', 'active');
        $this->applyManagerScope($brandQuery, $params);
        $brands = $brandQuery->orderBy('name')->get();

        if ($brands->isEmpty()) {
            return [];
        }

        $brandIds = $brands->pluck('id')->all();

        // Pre-fetch every active connection for these brands so we can fill
        // each row's `brand.platforms` array without an N+1.
        /** @var array<int, array<int, string>> */
        $platformsByBrand = PlatformConnection::query()
            ->whereIn('brand_id', $brandIds)
            ->where('status', 'active')
            ->get(['brand_id', 'platform'])
            ->groupBy('brand_id')
            ->map(fn ($rows) => $rows->pluck('platform')->unique()->values()->all())
            ->all();

        // Per-platform health flags so the table can distinguish "sync failed"
        // from "no orders that day". Without this we render "Shopify failed"
        // for any brand whose store legitimately had zero sales — wrong UX.
        /** @var array<int, array<string, array{status: string, lastSyncAt: ?string, hasError: bool}>> */
        $healthByBrand = PlatformConnection::query()
            ->whereIn('brand_id', $brandIds)
            ->get(['brand_id', 'platform', 'status', 'last_sync_at', 'last_error'])
            ->groupBy('brand_id')
            ->map(function ($rows) {
                return $rows->mapWithKeys(fn ($c) => [
                    $c->platform => [
                        'status'     => $c->status,
                        'lastSyncAt' => $c->last_sync_at?->toIso8601String(),
                        'hasError'   => $c->status === 'errored' || ! empty($c->last_error),
                    ],
                ])->all();
            })
            ->all();

        // Currency mode. ?currency=USD converts every brand to USD (the
        // blended view); absent/native renders each brand in its own currency.
        // Conversion is `revenue * fx_rate_to_usd` done in SQL (docs/10),
        // falling back to the native value (rate 1) only for a row whose USD
        // rate hasn't been backfilled yet — run `php artisan fx:apply` to fix
        // those. Expressions are built from constants, never user input.
        $usd         = strtoupper((string) ($params['currency'] ?? '')) === 'USD';
        $grossExpr   = $usd ? 'revenue * COALESCE(fx_rate_to_usd, 1)'        : 'revenue';
        $refundsExpr = $usd ? 'refunds_amount * COALESCE(fx_rate_to_usd, 1)' : 'refunds_amount';
        $netExpr     = $usd ? 'net_sales * COALESCE(fx_rate_to_usd, 1)'      : 'net_sales';
        // Total revenue = Shopify total_sales WITH refunds added back (Bosco,
        // 2026-06-25). total_sales already nets returns out, so we add the stored
        // refund magnitude back to show revenue gross of refunds. COALESCE so a
        // null on either column never nulls the whole sum.
        $totalCol    = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';
        $totalExpr   = $usd ? "{$totalCol} * COALESCE(fx_rate_to_usd, 1)" : $totalCol;

        // Year-over-year comparison (Bosco, 2026-06-19). Only the periods the UI
        // enabled are computed, so this is a no-op cost when Comparison is off.
        // Metric follows the dashboard's Net/Total toggle.
        $comparePeriods = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', (string) ($params['compare'] ?? '')))),
            ['yesterday', 'last7', 'last30', 'mtd'],
        ));
        $compareCol  = (($params['metric'] ?? 'total') === 'net') ? 'net_sales' : $totalCol;

        // Rolling comparison window (Bosco, 2026-07-02). The far-right block is
        // "last N days vs the prior N", N chosen from the dashboard's interval
        // filter; default 30 (last month). Allowlisted so it's never user SQL.
        $win = (int) ($params['window'] ?? 30);
        if (! in_array($win, [7, 30, 90], true)) {
            $win = 30;
        }

        $rows = $brands->map(function (Brand $b) use ($platformsByBrand, $healthByBrand, $usd, $grossExpr, $refundsExpr, $netExpr, $totalExpr, $comparePeriods, $compareCol, $win): array {
            $tz             = $b->timezone ?: 'UTC';
            $yesterdayDate  = CarbonImmutable::now($tz)->subDay()->startOfDay()->toDateString();
            $dayBeforeDate  = CarbonImmutable::now($tz)->subDays(2)->startOfDay()->toDateString();
            // Rolling window: [T-N, T-1]      — the N days ending yesterday
            // Prior window:   [T-2N, T-(N+1)] — the N days immediately before that
            $rollStart  = CarbonImmutable::now($tz)->subDays($win)->startOfDay()->toDateString();
            $rollEnd    = CarbonImmutable::now($tz)->subDay()->startOfDay()->toDateString();
            $priorStart = CarbonImmutable::now($tz)->subDays($win * 2)->startOfDay()->toDateString();
            $priorEnd   = CarbonImmutable::now($tz)->subDays($win + 1)->startOfDay()->toDateString();

            $yesterdayRow = $this->shopifyRow($b->id, $yesterdayDate);
            $dayBeforeRow = $this->shopifyRow($b->id, $dayBeforeDate);

            // Per-row USD multiplier for the single-day cells. Native mode it's
            // 1.0 (no-op); USD mode it's the row's snapshotted rate, falling
            // back to 1 for a row not yet backfilled.
            $yMult = $usd ? (float) ($yesterdayRow->fx_rate_to_usd ?? 1.0) : 1.0;
            $dMult = $usd ? (float) ($dayBeforeRow->fx_rate_to_usd ?? 1.0) : 1.0;

            // Ad spend per day per platform, each blended across the brand's
            // accounts at sync (one row per platform/brand/day). Display-currency
            // spend mirrors the revenue multiplier; ROAS is USD-normalized over
            // ALL ad platforms so the ratio is correct in either Native or USD.
            $yMeta   = $this->adRow($b->id, 'meta', $yesterdayDate);
            $dMeta   = $this->adRow($b->id, 'meta', $dayBeforeDate);
            $yGoogle = $this->adRow($b->id, 'google', $yesterdayDate);
            $dGoogle = $this->adRow($b->id, 'google', $dayBeforeDate);
            $yTikTok = $this->adRow($b->id, 'tiktok', $yesterdayDate);
            $dTikTok = $this->adRow($b->id, 'tiktok', $dayBeforeDate);

            $yMetaSpend   = $this->displaySpend($yMeta, $usd);
            $dMetaSpend   = $this->displaySpend($dMeta, $usd);
            $yGoogleSpend = $this->displaySpend($yGoogle, $usd);
            $dGoogleSpend = $this->displaySpend($dGoogle, $usd);
            $yTikTokSpend = $this->displaySpend($yTikTok, $usd);
            $dTikTokSpend = $this->displaySpend($dTikTok, $usd);

            // Blended total ad spend = Meta + Google + TikTok.
            $yTotalSpend = $this->sumSpend([$yMetaSpend, $yGoogleSpend, $yTikTokSpend]);
            $dTotalSpend = $this->sumSpend([$dMetaSpend, $dGoogleSpend, $dTikTokSpend]);

            // Blended ad spend (USD) per day, then ROAS for BOTH revenue bases —
            // net sales and total sales — so the dashboard renders ROAS against
            // whichever the metric toggle selects (Bosco-approved: ROAS follows
            // the filter).
            // Freshness gate (Bosco, 2026-06-30): a day only counts when its
            // Shopify row is FINALIZED (is_complete). A partial/in-progress day —
            // one synced while it was still "today" and never re-synced — must
            // never surface as a real number; those cells render "not synced"
            // instead. Showing a half-day's revenue/ROAS to a client is worse
            // than showing nothing.
            $yComplete  = (bool) ($yesterdayRow?->is_complete ?? false);
            $dbComplete = (bool) ($dayBeforeRow?->is_complete ?? false);

            $ySpendUsd  = $this->spendUsd([$yMeta, $yGoogle, $yTikTok]);
            $dSpendUsd  = $this->spendUsd([$dMeta, $dGoogle, $dTikTok]);
            // ROAS is gated on the revenue day being complete — a ratio built on a
            // partial numerator is the most dangerous number on the page.
            $yRoasNet   = $yComplete  ? $this->ratio($yesterdayRow, 'net_sales', $ySpendUsd) : null;
            $yRoasTotal = $yComplete  ? $this->ratioTotal($yesterdayRow, $ySpendUsd)         : null;
            $dRoasNet   = $dbComplete ? $this->ratio($dayBeforeRow, 'net_sales', $dSpendUsd) : null;
            $dRoasTotal = $dbComplete ? $this->ratioTotal($dayBeforeRow, $dSpendUsd)         : null;

            // Compute net = gross − refunds explicitly at read time. The
            // `revenue_net` column is also maintained at write time, but
            // recomputing it here keeps the formula visible in code and
            // immunizes the dashboard against any legacy rows that were
            // synced by an older RevenueFetcher (pre-bugfix).
            $rollTotals = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$rollStart, $rollEnd])
                ->selectRaw("
                    COALESCE(SUM({$grossExpr}), 0)   AS gross,
                    COALESCE(SUM({$refundsExpr}), 0) AS refunds,
                    COALESCE(SUM({$netExpr}), 0)     AS net,
                    COALESCE(SUM({$totalExpr}), 0)   AS total_sales
                ")
                ->first();

            $rollGross      = (float) ($rollTotals->gross ?? 0);
            $rollRefunds    = (float) ($rollTotals->refunds ?? 0);
            $rollNet        = $rollGross - $rollRefunds;
            $rollNetSales   = (float) ($rollTotals->net ?? 0);
            $rollTotalSales = (float) ($rollTotals->total_sales ?? 0);

            $rollCount = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$rollStart, $rollEnd])
                ->where('is_complete', true)
                ->count();

            // Prior-7d totals drive the comparison delta the dashboard
            // renders next to the L7d value. We compute the sums
            // unconditionally but only surface them when at least one day
            // landed in the window — a brand-new store with no prior
            // history shows "—" instead of a misleading €0 delta.
            $priorTotals = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$priorStart, $priorEnd])
                ->selectRaw("
                    COALESCE(SUM({$grossExpr}), 0)   AS gross,
                    COALESCE(SUM({$refundsExpr}), 0) AS refunds,
                    COALESCE(SUM({$netExpr}), 0)     AS net,
                    COALESCE(SUM({$totalExpr}), 0)   AS total_sales
                ")
                ->first();

            $priorGross      = (float) ($priorTotals->gross ?? 0);
            $priorRefunds    = (float) ($priorTotals->refunds ?? 0);
            $priorNet        = $priorGross - $priorRefunds;
            $priorNetSales   = (float) ($priorTotals->net ?? 0);
            $priorTotalSales = (float) ($priorTotals->total_sales ?? 0);

            $priorCount = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$priorStart, $priorEnd])
                ->count();

            // Per selected period: revenue, ad spend and ROAS this year vs the
            // SAME calendar dates last year. Spend/ROAS need historical ad data
            // (`php artisan ads:backfill-spend`); a window with no ad rows yet
            // renders "—" rather than a fake 0 / −100%.
            $comparison = [];
            foreach ($comparePeriods as $period) {
                [$start, $end] = $this->comparisonWindow($period, $tz);
                if ($start === null || $end === null) {
                    continue;
                }
                $lastStart = CarbonImmutable::parse($start)->subYear()->toDateString();
                $lastEnd   = CarbonImmutable::parse($end)->subYear()->toDateString();

                $revThis   = $this->comparisonRevenue($b->id, $compareCol, $usd, $start, $end);
                $revLast   = $this->comparisonRevenue($b->id, $compareCol, $usd, $lastStart, $lastEnd);
                $spendThis = $this->comparisonSpend($b->id, $usd, $start, $end);
                $spendLast = $this->comparisonSpend($b->id, $usd, $lastStart, $lastEnd);

                $comparison[$period] = [
                    'revenue' => ['thisYear' => $revThis['display'],   'lastYear' => $revLast['display']],
                    'spend'   => ['thisYear' => $spendThis['display'], 'lastYear' => $spendLast['display']],
                    'roas'    => [
                        'thisYear' => $this->comparisonRoas($revThis['usd'], $spendThis['usd']),
                        'lastYear' => $this->comparisonRoas($revLast['usd'], $spendLast['usd']),
                    ],
                ];
            }

            $health = $healthByBrand[$b->id] ?? [];

            return [
                'brand' => [
                    'id'           => $b->id,
                    'name'         => $b->name,
                    'slug'         => $b->slug,
                    'timezone'     => $b->timezone,
                    'baseCurrency' => $b->base_currency,
                    'groupTag'     => $b->group_tag,
                    'status'       => $b->status,
                    'initials'     => $this->initials($b->name),
                    'region'       => $b->group_tag ?? '—',
                    // Lets the SPA decide between "Shopify failed" (null +
                    // connection errored) and "N/A" (not connected) and "$0"
                    // (connection healthy, no orders that day).
                    'platforms'    => array_values($platformsByBrand[$b->id] ?? []),
                    'platformHealth' => $health,
                ],
                'yesterday' => [
                    // Gross + net both returned — the dashboard's Gross/Net
                    // toggle swaps between them at render time. Net is
                    // computed at query time as `revenue − refunds_amount`
                    // (see selectRaw above), not read from `revenue_net`.
                    // All revenue figures are gated on $yComplete — a partial
                    // (still-syncing) yesterday returns null so the row renders a
                    // "not synced" state, never a half-day number.
                    'revenue'     => $yComplete
                        ? round((float) $yesterdayRow->revenue * $yMult, 2)
                        : null,
                    'revenueNet'  => $yComplete
                        ? round(((float) $yesterdayRow->revenue - (float) $yesterdayRow->refunds_amount) * $yMult, 2)
                        : null,
                    'netSales'    => ($yComplete && $yesterdayRow->net_sales !== null)
                        ? round((float) $yesterdayRow->net_sales * $yMult, 2)
                        : null,
                    // Total revenue = total_sales + refunds (Bosco 2026-06-25);
                    // refundsAmount below still surfaces the refund on its own.
                    'totalSales'  => ($yComplete && $yesterdayRow->total_sales !== null)
                        ? round(((float) $yesterdayRow->total_sales + (float) $yesterdayRow->refunds_amount) * $yMult, 2)
                        : null,
                    'refundsAmount' => $yComplete
                        ? round((float) $yesterdayRow->refunds_amount * $yMult, 2)
                        : null,
                    'metaSpend'   => $yMetaSpend,
                    'googleSpend' => $yGoogleSpend,
                    'tiktokSpend' => $yTikTokSpend,
                    'totalSpend'  => $yTotalSpend,
                    'roas'        => $yRoasNet,
                    'roasTotal'   => $yRoasTotal,
                    'isComplete'  => (bool) ($yesterdayRow?->is_complete ?? false),
                ],
                'dayBefore' => [
                    // Gated on $dbComplete — same rule as yesterday.
                    'revenue'     => $dbComplete
                        ? round((float) $dayBeforeRow->revenue * $dMult, 2)
                        : null,
                    'revenueNet'  => $dbComplete
                        ? round(((float) $dayBeforeRow->revenue - (float) $dayBeforeRow->refunds_amount) * $dMult, 2)
                        : null,
                    'netSales'    => ($dbComplete && $dayBeforeRow->net_sales !== null)
                        ? round((float) $dayBeforeRow->net_sales * $dMult, 2)
                        : null,
                    'totalSales'  => ($dbComplete && $dayBeforeRow->total_sales !== null)
                        ? round(((float) $dayBeforeRow->total_sales + (float) $dayBeforeRow->refunds_amount) * $dMult, 2)
                        : null,
                    'refundsAmount' => $dbComplete
                        ? round((float) $dayBeforeRow->refunds_amount * $dMult, 2)
                        : null,
                    'metaSpend'   => $dMetaSpend,
                    'googleSpend' => $dGoogleSpend,
                    'tiktokSpend' => $dTikTokSpend,
                    'totalSpend'  => $dTotalSpend,
                    'roas'        => $dRoasNet,
                    'roasTotal'   => $dRoasTotal,
                    'isComplete'  => $dbComplete,
                ],
                'rolling' => [
                    // Strict: the whole window must be synced (all N days complete)
                    // or NOTHING shows — a partial-window sum labelled "last 30 days"
                    // is exactly the number that misleads (Bosco, 2026-06-30 / 07-02).
                    'windowDays'        => $win,
                    'revenue'           => $rollCount >= $win ? round($rollNet, 2)          : null,
                    'revenueGross'      => $rollCount >= $win ? round($rollGross, 2)        : null,
                    'netSales'          => $rollCount >= $win ? round($rollNetSales, 2)     : null,
                    'totalSales'        => $rollCount >= $win ? round($rollTotalSales, 2)   : null,
                    'revenuePrior'      => $priorCount >= $win ? round($priorNet, 2)        : null,
                    'revenueGrossPrior' => $priorCount >= $win ? round($priorGross, 2)      : null,
                    'netSalesPrior'     => $priorCount >= $win ? round($priorNetSales, 2)   : null,
                    'totalSalesPrior'   => $priorCount >= $win ? round($priorTotalSales, 2) : null,
                    'isComplete'        => $rollCount >= $win,
                ],
                'comparison' => $comparison,
            ];
        })->all();

        // Sort by last-7d net revenue desc — best performers at the top,
        // zero/missing revenue brands at the bottom. Tiebreak alphabetically
        // so the order is stable across requests when revenue ties (common
        // for brand-new stores all at 0). Net is the right signal here
        // rather than gross because refunds matter for "is this brand
        // actually performing".
        //
        // null revenue (brand has no L7d rows at all — likely a freshly
        // installed store or a sync that's never landed) sorts to the very
        // bottom and below 0-revenue brands, since at least 0 is a
        // confirmed signal.
        usort($rows, function (array $a, array $b): int {
            $aRev = $a['rolling']['revenue'];
            $bRev = $b['rolling']['revenue'];

            // Three-tier sort: confirmed revenue > confirmed zero > unknown.
            $aTier = $aRev === null ? 2 : ($aRev > 0 ? 0 : 1);
            $bTier = $bRev === null ? 2 : ($bRev > 0 ? 0 : 1);
            if ($aTier !== $bTier) {
                return $aTier <=> $bTier;
            }

            // Within "has revenue", sort high → low.
            if ($aTier === 0) {
                return $bRev <=> $aRev;
            }

            // Within "zero" or "unknown", sort alphabetically by brand name
            // so the trailing block reads predictably.
            return strcasecmp($a['brand']['name'], $b['brand']['name']);
        });

        return $rows;
    }

    // applyManagerScope() now lives in the ScopesBrandsByManager trait, shared
    // with AudienceQuery so the "Brand manager" filter never drifts between them.

    /**
     * [start, end] date strings (brand tz) for a year-over-year period THIS year.
     * Returns [null, null] when the window is empty (e.g. MTD on the 1st). Last
     * year is the same window shifted back one year (handled by the caller).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function comparisonWindow(string $period, string $tz): array
    {
        $now       = CarbonImmutable::now($tz);
        $yesterday = $now->subDay()->startOfDay();

        return match ($period) {
            'yesterday' => [$yesterday->toDateString(), $yesterday->toDateString()],
            'last7'     => [$now->subDays(7)->startOfDay()->toDateString(), $yesterday->toDateString()],
            'last30'    => [$now->subDays(30)->startOfDay()->toDateString(), $yesterday->toDateString()],
            'mtd'       => $yesterday->lessThan($now->startOfMonth())
                ? [null, null]
                : [$now->startOfMonth()->toDateString(), $yesterday->toDateString()],
            default     => [null, null],
        };
    }

    /**
     * Shopify revenue over [start, end] for the selected metric column, returning
     * BOTH the display-currency sum (for the column) and the USD-normalized sum
     * (for ROAS). Each is null — not 0 — when no rows landed, so the UI shows "—"
     * for a brand with no history that far back instead of a fake −100%.
     *
     * @return array{display: ?float, usd: ?float}
     */
    private function comparisonRevenue(int $brandId, string $col, bool $usd, string $start, string $end): array
    {
        $displayExpr = $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;
        $usdExpr     = "{$col} * COALESCE(fx_rate_to_usd, 1)";

        $row = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("COALESCE(SUM({$displayExpr}), 0) AS disp, COALESCE(SUM({$usdExpr}), 0) AS usd, COUNT(*) AS c")
            ->first();

        $count = (int) ($row->c ?? 0);

        return [
            'display' => $count > 0 ? round((float) ($row->disp ?? 0), 2) : null,
            'usd'     => $count > 0 ? (float) ($row->usd ?? 0) : null,
        ];
    }

    /**
     * Blended ad spend (Meta + Google + TikTok) over [start, end]: display-currency
     * sum (for the column) + USD-normalized sum (for ROAS). Null — not 0 — when no
     * ad rows landed in the window, so a period with no spend history (e.g. last
     * year before the platforms were connected, pre-`ads:backfill-spend`) renders
     * "—" rather than a misleading 0.
     *
     * @return array{display: ?float, usd: ?float}
     */
    private function comparisonSpend(int $brandId, bool $usd, string $start, string $end): array
    {
        $displayExpr = $usd ? 'spend * COALESCE(fx_rate_to_usd, 1)' : 'spend';
        $usdExpr     = 'spend * COALESCE(fx_rate_to_usd, 1)';

        $row = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', ['meta', 'google', 'tiktok'])
            ->whereBetween('date', [$start, $end])
            ->selectRaw("COALESCE(SUM({$displayExpr}), 0) AS disp, COALESCE(SUM({$usdExpr}), 0) AS usd, COUNT(*) AS c")
            ->first();

        $count = (int) ($row->c ?? 0);

        return [
            'display' => $count > 0 ? round((float) ($row->disp ?? 0), 2) : null,
            'usd'     => $count > 0 ? (float) ($row->usd ?? 0) : null,
        ];
    }

    /**
     * Blended ROAS over a comparison window = revenue(USD) ÷ spend(USD), matching
     * the dashboard's live ratio(). Null when either side is missing or spend is
     * zero — a brand with revenue but no ad spend that period reads "—", not 0×.
     */
    private function comparisonRoas(?float $revUsd, ?float $spendUsd): ?float
    {
        if ($revUsd === null || $spendUsd === null || $spendUsd <= 0.0) {
            return null;
        }

        return round($revUsd / $spendUsd, 2);
    }

    private function shopifyRow(int $brandId, string $date): ?DailyMetric
    {
        return DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->where('date', $date)
            ->first();
    }

    private function adRow(int $brandId, string $platform, string $date): ?DailyMetric
    {
        return DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('date', $date)
            ->first();
    }

    /**
     * Blended ad spend across every platform, normalized to USD via each row's
     * stored fx_rate so summing mixed-currency accounts is correct even in
     * Native mode. This is the denominator for both ROAS variants.
     *
     * @param array<int, ?DailyMetric> $adRows one per ad platform
     */
    private function spendUsd(array $adRows): float
    {
        $spendUsd = 0.0;
        foreach ($adRows as $adRow) {
            if ($adRow !== null) {
                $spendUsd += (float) $adRow->spend * (float) ($adRow->fx_rate_to_usd ?? 1.0);
            }
        }

        return $spendUsd;
    }

    /**
     * Blended ROAS = (revenue × fx) ÷ total ad spend (USD). `$field` picks the
     * revenue base — 'net_sales' or 'total_sales' — so the dashboard renders ROAS
     * against whichever metric the toggle has selected (Bosco-approved). Both are
     * Shopify's own ShopifyQL figures (channel = Online Store), never the
     * order-based gross `revenue`. Null when that revenue figure is missing or
     * total spend is zero (a brand with no ad platforms renders N/A, not 0×).
     */
    private function ratio(?DailyMetric $revRow, string $field, float $spendUsd): ?float
    {
        if ($revRow === null || $revRow->{$field} === null || $spendUsd <= 0.0) {
            return null;
        }
        $revUsd = (float) $revRow->{$field} * (float) ($revRow->fx_rate_to_usd ?? 1.0);

        return round($revUsd / $spendUsd, 2);
    }

    /**
     * ROAS for total revenue = (total_sales + refunds) ÷ spend, USD-normalized
     * (Bosco 2026-06-25) — mirrors the headline revenue, which adds refunds back.
     */
    private function ratioTotal(?DailyMetric $revRow, float $spendUsd): ?float
    {
        if ($revRow === null || $revRow->total_sales === null || $spendUsd <= 0.0) {
            return null;
        }
        $revUsd = ((float) $revRow->total_sales + (float) $revRow->refunds_amount)
            * (float) ($revRow->fx_rate_to_usd ?? 1.0);

        return round($revUsd / $spendUsd, 2);
    }

    /** Display-currency spend for one ad row (×fx in USD mode), or null. */
    private function displaySpend(?DailyMetric $row, bool $usd): ?float
    {
        if ($row === null) {
            return null;
        }

        return round((float) $row->spend * ($usd ? (float) ($row->fx_rate_to_usd ?? 1.0) : 1.0), 2);
    }

    /**
     * Sum non-null display spends into one total; null when every platform is
     * null (so "no ad platforms connected" renders N/A, not 0).
     *
     * @param array<int, ?float> $spends
     */
    private function sumSpend(array $spends): ?float
    {
        $present = array_filter($spends, static fn ($v) => $v !== null);

        return $present === [] ? null : round(array_sum($present), 2);
    }

    /** @param array<string, mixed> $params */
    public function summary(array $params): array
    {
        $brandCount = Brand::query()->where('status', 'active')->count();

        return [
            'totalRevenue' => 0,
            'totalSpend'   => 0,
            'roas'         => null,
            'currency'     => $params['currency'] ?? 'USD',
            'brandCount'   => $brandCount,
            'isComplete'   => false,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function trend(int $brandId, string $from, string $to, ?array $platforms = null): array
    {
        return [];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }
        return strtoupper(mb_substr($name, 0, 2));
    }
}
