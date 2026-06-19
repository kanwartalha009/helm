<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Assembles the data the dashboard table reads. Phase 1: Shopify revenue only
 * (Meta/Google/TikTok columns stay null — the frontend renders "N/A").
 *
 * Each row reports yesterday's and the day-before's revenue_net plus a
 * rolling 7-day sum for that brand. ROAS stays null until ad spend lands.
 */
final class DashboardQuery
{
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
        $totalExpr   = $usd ? 'total_sales * COALESCE(fx_rate_to_usd, 1)'    : 'total_sales';

        // Year-over-year comparison (Bosco, 2026-06-19). Only the periods the UI
        // enabled are computed, so this is a no-op cost when Comparison is off.
        // Metric follows the dashboard's Net/Total toggle.
        $comparePeriods = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', (string) ($params['compare'] ?? '')))),
            ['yesterday', 'last7', 'last30', 'mtd'],
        ));
        $compareCol  = (($params['metric'] ?? 'total') === 'net') ? 'net_sales' : 'total_sales';
        $compareExpr = $usd ? "{$compareCol} * COALESCE(fx_rate_to_usd, 1)" : $compareCol;

        $rows = $brands->map(function (Brand $b) use ($platformsByBrand, $healthByBrand, $usd, $grossExpr, $refundsExpr, $netExpr, $totalExpr, $comparePeriods, $compareExpr): array {
            $tz             = $b->timezone ?: 'UTC';
            $yesterdayDate  = CarbonImmutable::now($tz)->subDay()->startOfDay()->toDateString();
            $dayBeforeDate  = CarbonImmutable::now($tz)->subDays(2)->startOfDay()->toDateString();
            // L7d window:  [T-7, T-1]   — the 7 days ending yesterday
            // Prior 7d:    [T-14, T-8]  — the 7 days immediately before that
            $last7dStart    = CarbonImmutable::now($tz)->subDays(7)->startOfDay()->toDateString();
            $last7dEnd      = CarbonImmutable::now($tz)->subDay()->startOfDay()->toDateString();
            $prior7dStart   = CarbonImmutable::now($tz)->subDays(14)->startOfDay()->toDateString();
            $prior7dEnd     = CarbonImmutable::now($tz)->subDays(8)->startOfDay()->toDateString();

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
            $ySpendUsd  = $this->spendUsd([$yMeta, $yGoogle, $yTikTok]);
            $dSpendUsd  = $this->spendUsd([$dMeta, $dGoogle, $dTikTok]);
            $yRoasNet   = $this->ratio($yesterdayRow, 'net_sales',   $ySpendUsd);
            $yRoasTotal = $this->ratio($yesterdayRow, 'total_sales', $ySpendUsd);
            $dRoasNet   = $this->ratio($dayBeforeRow, 'net_sales',   $dSpendUsd);
            $dRoasTotal = $this->ratio($dayBeforeRow, 'total_sales', $dSpendUsd);

            // Compute net = gross − refunds explicitly at read time. The
            // `revenue_net` column is also maintained at write time, but
            // recomputing it here keeps the formula visible in code and
            // immunizes the dashboard against any legacy rows that were
            // synced by an older RevenueFetcher (pre-bugfix).
            $last7dTotals = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$last7dStart, $last7dEnd])
                ->selectRaw("
                    COALESCE(SUM({$grossExpr}), 0)   AS gross,
                    COALESCE(SUM({$refundsExpr}), 0) AS refunds,
                    COALESCE(SUM({$netExpr}), 0)     AS net,
                    COALESCE(SUM({$totalExpr}), 0)   AS total_sales
                ")
                ->first();

            $last7dGross      = (float) ($last7dTotals->gross ?? 0);
            $last7dRefunds    = (float) ($last7dTotals->refunds ?? 0);
            $last7dNet        = $last7dGross - $last7dRefunds;
            $last7dNetSales   = (float) ($last7dTotals->net ?? 0);
            $last7dTotalSales = (float) ($last7dTotals->total_sales ?? 0);

            $last7dCount = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$last7dStart, $last7dEnd])
                ->where('is_complete', true)
                ->count();

            // Prior-7d totals drive the comparison delta the dashboard
            // renders next to the L7d value. We compute the sums
            // unconditionally but only surface them when at least one day
            // landed in the window — a brand-new store with no prior
            // history shows "—" instead of a misleading €0 delta.
            $prior7dTotals = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$prior7dStart, $prior7dEnd])
                ->selectRaw("
                    COALESCE(SUM({$grossExpr}), 0)   AS gross,
                    COALESCE(SUM({$refundsExpr}), 0) AS refunds,
                    COALESCE(SUM({$netExpr}), 0)     AS net,
                    COALESCE(SUM({$totalExpr}), 0)   AS total_sales
                ")
                ->first();

            $prior7dGross      = (float) ($prior7dTotals->gross ?? 0);
            $prior7dRefunds    = (float) ($prior7dTotals->refunds ?? 0);
            $prior7dNet        = $prior7dGross - $prior7dRefunds;
            $prior7dNetSales   = (float) ($prior7dTotals->net ?? 0);
            $prior7dTotalSales = (float) ($prior7dTotals->total_sales ?? 0);

            $prior7dCount = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$prior7dStart, $prior7dEnd])
                ->count();

            // Per selected period: this year vs the same calendar dates last year.
            $comparison = [];
            foreach ($comparePeriods as $period) {
                [$start, $end] = $this->comparisonWindow($period, $tz);
                if ($start === null || $end === null) {
                    continue;
                }
                $lastStart = CarbonImmutable::parse($start)->subYear()->toDateString();
                $lastEnd   = CarbonImmutable::parse($end)->subYear()->toDateString();
                $comparison[$period] = [
                    'thisYear' => $this->comparisonSum($b->id, $compareExpr, $start, $end),
                    'lastYear' => $this->comparisonSum($b->id, $compareExpr, $lastStart, $lastEnd),
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
                    'revenue'     => $yesterdayRow
                        ? round((float) $yesterdayRow->revenue * $yMult, 2)
                        : null,
                    'revenueNet'  => $yesterdayRow
                        ? round(((float) $yesterdayRow->revenue - (float) $yesterdayRow->refunds_amount) * $yMult, 2)
                        : null,
                    'netSales'    => ($yesterdayRow && $yesterdayRow->net_sales !== null)
                        ? round((float) $yesterdayRow->net_sales * $yMult, 2)
                        : null,
                    'totalSales'  => ($yesterdayRow && $yesterdayRow->total_sales !== null)
                        ? round((float) $yesterdayRow->total_sales * $yMult, 2)
                        : null,
                    'refundsAmount' => $yesterdayRow
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
                    'revenue'     => $dayBeforeRow
                        ? round((float) $dayBeforeRow->revenue * $dMult, 2)
                        : null,
                    'revenueNet'  => $dayBeforeRow
                        ? round(((float) $dayBeforeRow->revenue - (float) $dayBeforeRow->refunds_amount) * $dMult, 2)
                        : null,
                    'netSales'    => ($dayBeforeRow && $dayBeforeRow->net_sales !== null)
                        ? round((float) $dayBeforeRow->net_sales * $dMult, 2)
                        : null,
                    'totalSales'  => ($dayBeforeRow && $dayBeforeRow->total_sales !== null)
                        ? round((float) $dayBeforeRow->total_sales * $dMult, 2)
                        : null,
                    'refundsAmount' => $dayBeforeRow
                        ? round((float) $dayBeforeRow->refunds_amount * $dMult, 2)
                        : null,
                    'metaSpend'   => $dMetaSpend,
                    'googleSpend' => $dGoogleSpend,
                    'tiktokSpend' => $dTikTokSpend,
                    'totalSpend'  => $dTotalSpend,
                    'roas'        => $dRoasNet,
                    'roasTotal'   => $dRoasTotal,
                ],
                'last7d' => [
                    'revenue'             => $last7dCount > 0 ? round($last7dNet, 2)          : null,
                    'revenueGross'        => $last7dCount > 0 ? round($last7dGross, 2)        : null,
                    'netSales'            => $last7dCount > 0 ? round($last7dNetSales, 2)     : null,
                    'totalSales'          => $last7dCount > 0 ? round($last7dTotalSales, 2)   : null,
                    'revenuePrior7d'      => $prior7dCount > 0 ? round($prior7dNet, 2)        : null,
                    'revenueGrossPrior7d' => $prior7dCount > 0 ? round($prior7dGross, 2)      : null,
                    'netSalesPrior7d'     => $prior7dCount > 0 ? round($prior7dNetSales, 2)   : null,
                    'totalSalesPrior7d'   => $prior7dCount > 0 ? round($prior7dTotalSales, 2) : null,
                    'isComplete'          => $last7dCount >= 7,
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
            $aRev = $a['last7d']['revenue'];
            $bRev = $b['last7d']['revenue'];

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

    /**
     * Scope the dashboard's brand set by the "Brand manager" filter. Spec §08
     * keeps limited roles hard-scoped via the Brand global access scope; this
     * adds the admin/manager soft default + filter on top:
     *
     *   manager = 'me' (default) → the signed-in user's assigned brands
     *   manager = 'all'          → every brand (privileged only; limited roles
     *                              stay confined by the global access scope)
     *   manager = <user id>      → that user's assigned brands
     *
     * Soft default: a privileged user defaulting to "my brands" who has no
     * assignments yet sees everything rather than an empty board.
     */
    private function applyManagerScope(Builder $query, array $params): void
    {
        $me           = Auth::user();
        $isPrivileged = $me !== null && in_array($me->role, ['master_admin', 'manager'], true);

        $manager = (string) ($params['manager'] ?? 'me');
        if ($manager === '') {
            $manager = 'me';
        }
        if ($manager === 'all') {
            return;
        }

        $scopeUserId = $manager === 'me'
            ? $me?->id
            : (ctype_digit($manager) ? (int) $manager : null);
        if ($scopeUserId === null) {
            return; // unknown value → treat as 'all' (limited roles still globally scoped)
        }

        $scopeUser   = ($me && $scopeUserId === $me->id) ? $me : User::find($scopeUserId);
        $assignedIds = $scopeUser?->accessibleBrandIds() ?? [];

        if ($assignedIds === [] && $manager === 'me' && $isPrivileged) {
            return; // soft default, no assignments → show all
        }

        // Specific manager (or a limited user) with no brands → honest empty board.
        $query->whereIn('id', $assignedIds !== [] ? $assignedIds : [0]);
    }

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
     * Sum the metric expression over [start, end] for a brand's Shopify rows.
     * Returns null (not 0) when no rows landed in the window, so the UI can show
     * "—" for a brand that didn't exist last year instead of a fake −100%.
     */
    private function comparisonSum(int $brandId, string $expr, string $start, string $end): ?float
    {
        $row = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("COALESCE(SUM({$expr}), 0) AS s, COUNT(*) AS c")
            ->first();

        return ((int) ($row->c ?? 0)) > 0 ? round((float) ($row->s ?? 0), 2) : null;
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
