<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Services\Aggregation\Concerns\ScopesBrandsByManager;
use Carbon\CarbonImmutable;

/**
 * Set-based reimplementation of DashboardQuery (audit 2026-07-10, headline
 * scale finding). The legacy engine runs ~12 queries per brand (~960 at 80
 * brands, ~12,000 at 1,000); this one runs a constant handful of GROUP BY
 * brand_id queries per distinct brand "today" (timezone group), regardless
 * of brand count.
 *
 * Contract: run() returns EXACTLY the same rows as DashboardQuery::run() for
 * the same params and data — same fields, same gating, same rounding, same
 * sort. Verified by `php artisan helm:dashboard-parity` (live data) and the
 * DashboardEngineParityTest suite (seeded data). Selected via
 * config('helm.dashboard_engine') — see config/helm.php; the legacy engine
 * stays the default until parity is confirmed in production.
 *
 * Additive extras (ignored by the parity diff, consumed by the SPA once the
 * engine is flipped):
 *  - yesterday/dayBefore `fxPending`: true when USD mode is on and the row's
 *    fx_rate_to_usd is still null — the value on screen used rate 1.0 and
 *    should render with a pending marker, not as a trustworthy figure.
 *  - rolling `fxPendingDays`: how many window days still await an FX rate.
 *
 * Timezone note: every brand's "yesterday" is derived from now() in ITS
 * timezone (AGENTS.md rule 8). Brands are therefore grouped by their current
 * local DATE — all brands sharing a calendar date share every window — and
 * each group gets one set of grouped queries.
 */
final class DashboardQuerySetBased
{
    use ScopesBrandsByManager;

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function run(array $params): array
    {
        $brandQuery = Brand::query()->where('status', 'active');
        $this->applyManagerScope($brandQuery, $params);
        $brands = $brandQuery->orderBy('name')->get();

        if ($brands->isEmpty()) {
            return [];
        }

        $brandIds = $brands->pluck('id')->all();

        // Active connections → brand.platforms (same as legacy).
        /** @var array<int, array<int, string>> */
        $platformsByBrand = PlatformConnection::query()
            ->whereIn('brand_id', $brandIds)
            ->where('status', 'active')
            ->get(['brand_id', 'platform'])
            ->groupBy('brand_id')
            ->map(fn ($rows) => $rows->pluck('platform')->unique()->values()->all())
            ->all();

        // Per-platform health flags (same as legacy).
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

        // Currency mode + SQL expressions — identical constants to legacy.
        $usd         = strtoupper((string) ($params['currency'] ?? '')) === 'USD';
        $grossExpr   = $usd ? 'revenue * COALESCE(fx_rate_to_usd, 1)'        : 'revenue';
        $refundsExpr = $usd ? 'refunds_amount * COALESCE(fx_rate_to_usd, 1)' : 'refunds_amount';
        $netExpr     = $usd ? 'net_sales * COALESCE(fx_rate_to_usd, 1)'      : 'net_sales';
        $totalCol    = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';
        $totalExpr   = $usd ? "{$totalCol} * COALESCE(fx_rate_to_usd, 1)" : $totalCol;

        $comparePeriods = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', (string) ($params['compare'] ?? '')))),
            ['yesterday', 'last7', 'last30', 'lastmonth', 'mtd'],
        ));
        $compareCol = (($params['metric'] ?? 'total') === 'net') ? 'net_sales' : $totalCol;

        $win = (int) ($params['window'] ?? 7);
        if (! in_array($win, [7, 30, 90], true)) {
            $win = 7;
        }

        // ---- Group brands by their current local date ----------------------
        /** @var array<string, array<int, int>> $groups nowDate => brand ids */
        $groups = [];
        /** @var array<int, string> $todayByBrand */
        $todayByBrand = [];
        foreach ($brands as $b) {
            $key = CarbonImmutable::now($b->timezone ?: 'UTC')->toDateString();
            $groups[$key][]        = $b->id;
            $todayByBrand[$b->id]  = $key;
        }

        /** @var array<int, array<string, array<string, DailyMetric>>> $dayRows [brandId][platform]['y'|'d'] */
        $dayRows = [];
        /** @var array<int, array<string, mixed>> $windowAgg [brandId] => grouped sums */
        $windowAgg = [];
        /** @var array<int, array<string, array{this: array{display: ?float, usd: ?float}, last: array{display: ?float, usd: ?float}}>> $cmpRev */
        $cmpRev = [];
        /** @var array<int, array<string, array{this: array{display: ?float, usd: ?float}, last: array{display: ?float, usd: ?float}}>> $cmpSpend */
        $cmpSpend = [];
        /** @var array<string, array<string, bool>> $cmpWindows [todayKey][period] => window existed for that group */
        $cmpWindows = [];

        foreach ($groups as $todayKey => $ids) {
            $today      = CarbonImmutable::parse($todayKey)->startOfDay();
            $yDate      = $today->subDay()->toDateString();
            $dDate      = $today->subDays(2)->toDateString();
            $rollStart  = $today->subDays($win)->toDateString();
            $rollEnd    = $yDate;
            $priorStart = $today->subDays($win * 2)->toDateString();
            $priorEnd   = $today->subDays($win + 1)->toDateString();

            // (1) Yesterday + day-before rows, every platform, one query.
            DailyMetric::query()
                ->whereIn('brand_id', $ids)
                ->whereIn('date', [$yDate, $dDate])
                ->get()
                ->each(function (DailyMetric $r) use (&$dayRows, $yDate): void {
                    $slot = $r->date->toDateString() === $yDate ? 'y' : 'd';
                    $dayRows[$r->brand_id][$r->platform][$slot] = $r;
                });

            // (2) Rolling + prior Shopify windows, one grouped query. The CASE
            // bounds are inclusive date strings — identical row selection to
            // legacy's whereBetween. Sum expressions are the same constants.
            $rows = DailyMetric::query()
                ->whereIn('brand_id', $ids)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$priorStart, $rollEnd])
                ->groupBy('brand_id')
                ->selectRaw(
                    'brand_id,'
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$grossExpr}   ELSE 0 END), 0) AS roll_gross,"
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$refundsExpr} ELSE 0 END), 0) AS roll_refunds,"
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$netExpr}     ELSE 0 END), 0) AS roll_net,"
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$totalExpr}   ELSE 0 END), 0) AS roll_total,"
                    . ' SUM(CASE WHEN date >= ? AND date <= ? AND is_complete THEN 1 ELSE 0 END)           AS roll_complete_days,'
                    . ' SUM(CASE WHEN date >= ? AND date <= ? AND fx_rate_to_usd IS NULL THEN 1 ELSE 0 END) AS roll_fx_pending,'
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$grossExpr}   ELSE 0 END), 0) AS prior_gross,"
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$refundsExpr} ELSE 0 END), 0) AS prior_refunds,"
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$netExpr}     ELSE 0 END), 0) AS prior_net,"
                    . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$totalExpr}   ELSE 0 END), 0) AS prior_total,"
                    . ' SUM(CASE WHEN date >= ? AND date <= ? THEN 1 ELSE 0 END)                           AS prior_days',
                    [
                        $rollStart, $rollEnd,   $rollStart, $rollEnd,
                        $rollStart, $rollEnd,   $rollStart, $rollEnd,
                        $rollStart, $rollEnd,   $rollStart, $rollEnd,
                        $priorStart, $priorEnd, $priorStart, $priorEnd,
                        $priorStart, $priorEnd, $priorStart, $priorEnd,
                        $priorStart, $priorEnd,
                    ],
                )
                ->get();
            foreach ($rows as $r) {
                $windowAgg[(int) $r->brand_id] = $r->getAttributes();
            }

            // (3) Year-over-year comparisons: two grouped queries per period
            // (revenue on Shopify rows, blended spend on ad rows), each
            // covering this year's window and the same window last year.
            foreach ($comparePeriods as $period) {
                [$start, $end] = $this->comparisonWindow($period, $today);
                if ($start === null || $end === null) {
                    continue; // e.g. MTD on the 1st — legacy omits the period for this group
                }
                $cmpWindows[$todayKey][$period] = true;
                $lastStart = CarbonImmutable::parse($start)->subYear()->toDateString();
                $lastEnd   = CarbonImmutable::parse($end)->subYear()->toDateString();

                $revDisplayExpr = $usd ? "{$compareCol} * COALESCE(fx_rate_to_usd, 1)" : $compareCol;
                $revUsdExpr     = "{$compareCol} * COALESCE(fx_rate_to_usd, 1)";

                $this->bucketComparison(
                    $cmpRev, $period, $ids,
                    platforms: ['shopify'],
                    displayExpr: $revDisplayExpr, usdExpr: $revUsdExpr,
                    thisStart: $start, thisEnd: $end, lastStart: $lastStart, lastEnd: $lastEnd,
                );

                $spendDisplayExpr = $usd ? 'spend * COALESCE(fx_rate_to_usd, 1)' : 'spend';
                $spendUsdExpr     = 'spend * COALESCE(fx_rate_to_usd, 1)';

                $this->bucketComparison(
                    $cmpSpend, $period, $ids,
                    platforms: ['meta', 'google', 'tiktok'],
                    displayExpr: $spendDisplayExpr, usdExpr: $spendUsdExpr,
                    thisStart: $start, thisEnd: $end, lastStart: $lastStart, lastEnd: $lastEnd,
                );
            }
        }

        // ---- Assemble rows — the math below is copied from the legacy engine
        // line for line (same gating, same rounding), reading from the buckets
        // instead of running per-brand queries. -----------------------------
        $rows = $brands->map(function (Brand $b) use (
            $platformsByBrand, $healthByBrand, $usd, $comparePeriods,
            $dayRows, $windowAgg, $cmpRev, $cmpSpend, $cmpWindows, $todayByBrand, $win
        ): array {
            $yesterdayRow = $dayRows[$b->id]['shopify']['y'] ?? null;
            $dayBeforeRow = $dayRows[$b->id]['shopify']['d'] ?? null;

            $yMult = $usd ? (float) ($yesterdayRow->fx_rate_to_usd ?? 1.0) : 1.0;
            $dMult = $usd ? (float) ($dayBeforeRow->fx_rate_to_usd ?? 1.0) : 1.0;

            $yMeta   = $dayRows[$b->id]['meta']['y'] ?? null;
            $dMeta   = $dayRows[$b->id]['meta']['d'] ?? null;
            $yGoogle = $dayRows[$b->id]['google']['y'] ?? null;
            $dGoogle = $dayRows[$b->id]['google']['d'] ?? null;
            $yTikTok = $dayRows[$b->id]['tiktok']['y'] ?? null;
            $dTikTok = $dayRows[$b->id]['tiktok']['d'] ?? null;

            $yMetaSpend   = $this->displaySpend($yMeta, $usd);
            $dMetaSpend   = $this->displaySpend($dMeta, $usd);
            $yGoogleSpend = $this->displaySpend($yGoogle, $usd);
            $dGoogleSpend = $this->displaySpend($dGoogle, $usd);
            $yTikTokSpend = $this->displaySpend($yTikTok, $usd);
            $dTikTokSpend = $this->displaySpend($dTikTok, $usd);

            $yTotalSpend = $this->sumSpend([$yMetaSpend, $yGoogleSpend, $yTikTokSpend]);
            $dTotalSpend = $this->sumSpend([$dMetaSpend, $dGoogleSpend, $dTikTokSpend]);

            $yComplete  = (bool) ($yesterdayRow?->is_complete ?? false);
            $dbComplete = (bool) ($dayBeforeRow?->is_complete ?? false);

            $ySpendUsd  = $this->spendUsd([$yMeta, $yGoogle, $yTikTok]);
            $dSpendUsd  = $this->spendUsd([$dMeta, $dGoogle, $dTikTok]);
            $yRoasNet   = $yComplete  ? $this->ratio($yesterdayRow, 'net_sales', $ySpendUsd) : null;
            $yRoasTotal = $yComplete  ? $this->ratioTotal($yesterdayRow, $ySpendUsd)         : null;
            $dRoasNet   = $dbComplete ? $this->ratio($dayBeforeRow, 'net_sales', $dSpendUsd) : null;
            $dRoasTotal = $dbComplete ? $this->ratioTotal($dayBeforeRow, $dSpendUsd)         : null;

            $agg             = $windowAgg[$b->id] ?? [];
            $rollGross       = (float) ($agg['roll_gross'] ?? 0);
            $rollRefunds     = (float) ($agg['roll_refunds'] ?? 0);
            $rollNet         = $rollGross - $rollRefunds;
            $rollNetSales    = (float) ($agg['roll_net'] ?? 0);
            $rollTotalSales  = (float) ($agg['roll_total'] ?? 0);
            $rollCount       = (int) ($agg['roll_complete_days'] ?? 0);
            $rollFxPending   = (int) ($agg['roll_fx_pending'] ?? 0);
            $priorGross      = (float) ($agg['prior_gross'] ?? 0);
            $priorRefunds    = (float) ($agg['prior_refunds'] ?? 0);
            $priorNet        = $priorGross - $priorRefunds;
            $priorNetSales   = (float) ($agg['prior_net'] ?? 0);
            $priorTotalSales = (float) ($agg['prior_total'] ?? 0);
            $priorCount      = (int) ($agg['prior_days'] ?? 0);

            $comparison = [];
            foreach ($comparePeriods as $period) {
                // Legacy emits the period for every brand whose own tz-window
                // exists (nulls when that brand simply has no rows), and omits
                // it when the window itself was empty (e.g. MTD on the 1st).
                if (! isset($cmpWindows[$todayByBrand[$b->id]][$period])) {
                    continue;
                }
                $revThis   = $cmpRev[$b->id][$period]['this'] ?? ['display' => null, 'usd' => null];
                $revLast   = $cmpRev[$b->id][$period]['last'] ?? ['display' => null, 'usd' => null];
                $spendThis = $cmpSpend[$b->id][$period]['this'] ?? ['display' => null, 'usd' => null];
                $spendLast = $cmpSpend[$b->id][$period]['last'] ?? ['display' => null, 'usd' => null];

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
                    'platforms'    => array_values($platformsByBrand[$b->id] ?? []),
                    'platformHealth' => $health,
                ],
                'yesterday' => [
                    'revenue'     => $yComplete
                        ? round((float) $yesterdayRow->revenue * $yMult, 2)
                        : null,
                    'revenueNet'  => $yComplete
                        ? round(((float) $yesterdayRow->revenue - (float) $yesterdayRow->refunds_amount) * $yMult, 2)
                        : null,
                    'netSales'    => ($yComplete && $yesterdayRow->net_sales !== null)
                        ? round((float) $yesterdayRow->net_sales * $yMult, 2)
                        : null,
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
                    // Additive (not in legacy): the figure above used rate 1.0
                    // because the FX snapshot hasn't been backfilled yet.
                    'fxPending'   => $usd && $yComplete && $yesterdayRow !== null && $yesterdayRow->fx_rate_to_usd === null,
                ],
                'dayBefore' => [
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
                    'fxPending'   => $usd && $dbComplete && $dayBeforeRow !== null && $dayBeforeRow->fx_rate_to_usd === null,
                ],
                'rolling' => [
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
                    // Additive (not in legacy): window days still awaiting FX.
                    'fxPendingDays'     => $rollFxPending,
                ],
                'comparison' => $comparison,
            ];
        })->all();

        // Identical three-tier sort to legacy.
        usort($rows, function (array $a, array $b): int {
            $aRev = $a['rolling']['revenue'];
            $bRev = $b['rolling']['revenue'];

            $aTier = $aRev === null ? 2 : ($aRev > 0 ? 0 : 1);
            $bTier = $bRev === null ? 2 : ($bRev > 0 ? 0 : 1);
            if ($aTier !== $bTier) {
                return $aTier <=> $bTier;
            }

            if ($aTier === 0) {
                return $bRev <=> $aRev;
            }

            return strcasecmp($a['brand']['name'], $b['brand']['name']);
        });

        return $rows;
    }

    /**
     * One grouped comparison query: per-brand sums + counts for this year's
     * window and last year's, in a single pass. Buckets into
     * $out[brandId][period]['this'|'last'] with the legacy null-when-no-rows
     * rule; the caller records the window's existence per timezone group so
     * the assembly loop emits the period even for brands with zero rows
     * (legacy emits the period for every brand whose window exists).
     *
     * @param array<int|string, mixed> $out
     * @param array<int, int>          $ids
     * @param array<int, string>       $platforms
     */
    private function bucketComparison(
        array &$out,
        string $period,
        array $ids,
        array $platforms,
        string $displayExpr,
        string $usdExpr,
        string $thisStart,
        string $thisEnd,
        string $lastStart,
        string $lastEnd,
    ): void {
        $rows = DailyMetric::query()
            ->whereIn('brand_id', $ids)
            ->whereIn('platform', $platforms)
            ->where(function ($q) use ($thisStart, $thisEnd, $lastStart, $lastEnd): void {
                $q->whereBetween('date', [$thisStart, $thisEnd])
                    ->orWhereBetween('date', [$lastStart, $lastEnd]);
            })
            ->groupBy('brand_id')
            ->selectRaw(
                'brand_id,'
                . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$displayExpr} ELSE 0 END), 0) AS this_disp,"
                . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$usdExpr}     ELSE 0 END), 0) AS this_usd,"
                . ' SUM(CASE WHEN date >= ? AND date <= ? THEN 1 ELSE 0 END)                           AS this_c,'
                . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$displayExpr} ELSE 0 END), 0) AS last_disp,"
                . " COALESCE(SUM(CASE WHEN date >= ? AND date <= ? THEN {$usdExpr}     ELSE 0 END), 0) AS last_usd,"
                . ' SUM(CASE WHEN date >= ? AND date <= ? THEN 1 ELSE 0 END)                           AS last_c',
                [
                    $thisStart, $thisEnd, $thisStart, $thisEnd, $thisStart, $thisEnd,
                    $lastStart, $lastEnd, $lastStart, $lastEnd, $lastStart, $lastEnd,
                ],
            )
            ->get();

        foreach ($rows as $r) {
            $out[(int) $r->brand_id][$period] = [
                'this' => [
                    'display' => ((int) $r->this_c) > 0 ? round((float) $r->this_disp, 2) : null,
                    'usd'     => ((int) $r->this_c) > 0 ? (float) $r->this_usd : null,
                ],
                'last' => [
                    'display' => ((int) $r->last_c) > 0 ? round((float) $r->last_disp, 2) : null,
                    'usd'     => ((int) $r->last_c) > 0 ? (float) $r->last_usd : null,
                ],
            ];
        }
    }

    /**
     * [start, end] date strings for a year-over-year period THIS year, anchored
     * on the group's local "today" — same windows as the legacy engine's
     * comparisonWindow(now($tz)).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function comparisonWindow(string $period, CarbonImmutable $today): array
    {
        $yesterday = $today->subDay();

        return match ($period) {
            'yesterday' => [$yesterday->toDateString(), $yesterday->toDateString()],
            'last7'     => [$today->subDays(7)->toDateString(), $yesterday->toDateString()],
            'last30'    => [$today->subDays(30)->toDateString(), $yesterday->toDateString()],
            'lastmonth' => [
                $today->startOfMonth()->subMonthNoOverflow()->toDateString(),
                $today->startOfMonth()->subDay()->toDateString(),
            ],
            'mtd'       => $yesterday->lessThan($today->startOfMonth())
                ? [null, null]
                : [$today->startOfMonth()->toDateString(), $yesterday->toDateString()],
            default     => [null, null],
        };
    }

    private function comparisonRoas(?float $revUsd, ?float $spendUsd): ?float
    {
        if ($revUsd === null || $spendUsd === null || $spendUsd <= 0.0) {
            return null;
        }

        return round($revUsd / $spendUsd, 2);
    }

    /** @param array<int, ?DailyMetric> $adRows */
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

    private function ratio(?DailyMetric $revRow, string $field, float $spendUsd): ?float
    {
        if ($revRow === null || $revRow->{$field} === null || $spendUsd <= 0.0) {
            return null;
        }
        $revUsd = (float) $revRow->{$field} * (float) ($revRow->fx_rate_to_usd ?? 1.0);

        return round($revUsd / $spendUsd, 2);
    }

    private function ratioTotal(?DailyMetric $revRow, float $spendUsd): ?float
    {
        if ($revRow === null || $revRow->total_sales === null || $spendUsd <= 0.0) {
            return null;
        }
        $revUsd = ((float) $revRow->total_sales + (float) $revRow->refunds_amount)
            * (float) ($revRow->fx_rate_to_usd ?? 1.0);

        return round($revUsd / $spendUsd, 2);
    }

    private function displaySpend(?DailyMetric $row, bool $usd): ?float
    {
        if ($row === null) {
            return null;
        }

        return round((float) $row->spend * ($usd ? (float) ($row->fx_rate_to_usd ?? 1.0) : 1.0), 2);
    }

    /** @param array<int, ?float> $spends */
    private function sumSpend(array $spends): ?float
    {
        $present = array_filter($spends, static fn ($v) => $v !== null);

        return $present === [] ? null : round(array_sum($present), 2);
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
