<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
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
    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function run(array $params): array
    {
        $brands = Brand::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

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

        return $brands->map(function (Brand $b) use ($platformsByBrand, $healthByBrand): array {
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

            // Compute net = gross − refunds explicitly at read time. The
            // `revenue_net` column is also maintained at write time, but
            // recomputing it here keeps the formula visible in code and
            // immunizes the dashboard against any legacy rows that were
            // synced by an older RevenueFetcher (pre-bugfix).
            $last7dTotals = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$last7dStart, $last7dEnd])
                ->selectRaw('
                    COALESCE(SUM(revenue), 0)        AS gross,
                    COALESCE(SUM(refunds_amount), 0) AS refunds
                ')
                ->first();

            $last7dGross   = (float) ($last7dTotals->gross ?? 0);
            $last7dRefunds = (float) ($last7dTotals->refunds ?? 0);
            $last7dNet     = $last7dGross - $last7dRefunds;

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
                ->selectRaw('
                    COALESCE(SUM(revenue), 0)        AS gross,
                    COALESCE(SUM(refunds_amount), 0) AS refunds
                ')
                ->first();

            $prior7dGross   = (float) ($prior7dTotals->gross ?? 0);
            $prior7dRefunds = (float) ($prior7dTotals->refunds ?? 0);
            $prior7dNet     = $prior7dGross - $prior7dRefunds;

            $prior7dCount = DailyMetric::query()
                ->where('brand_id', $b->id)
                ->where('platform', 'shopify')
                ->whereBetween('date', [$prior7dStart, $prior7dEnd])
                ->count();

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
                        ? round((float) $yesterdayRow->revenue, 2)
                        : null,
                    'revenueNet'  => $yesterdayRow
                        ? round((float) $yesterdayRow->revenue - (float) $yesterdayRow->refunds_amount, 2)
                        : null,
                    'refundsAmount' => $yesterdayRow
                        ? round((float) $yesterdayRow->refunds_amount, 2)
                        : null,
                    'metaSpend'   => null,
                    'googleSpend' => null,
                    'tiktokSpend' => null,
                    'totalSpend'  => null,
                    'roas'        => null,
                    'isComplete'  => (bool) ($yesterdayRow?->is_complete ?? false),
                ],
                'dayBefore' => [
                    'revenue'     => $dayBeforeRow
                        ? round((float) $dayBeforeRow->revenue, 2)
                        : null,
                    'revenueNet'  => $dayBeforeRow
                        ? round((float) $dayBeforeRow->revenue - (float) $dayBeforeRow->refunds_amount, 2)
                        : null,
                    'refundsAmount' => $dayBeforeRow
                        ? round((float) $dayBeforeRow->refunds_amount, 2)
                        : null,
                    'metaSpend'   => null,
                    'googleSpend' => null,
                    'tiktokSpend' => null,
                    'totalSpend'  => null,
                    'roas'        => null,
                ],
                'last7d' => [
                    'revenue'             => $last7dCount > 0 ? round($last7dNet, 2)   : null,
                    'revenueGross'        => $last7dCount > 0 ? round($last7dGross, 2) : null,
                    'revenuePrior7d'      => $prior7dCount > 0 ? round($prior7dNet, 2)   : null,
                    'revenueGrossPrior7d' => $prior7dCount > 0 ? round($prior7dGross, 2) : null,
                    'isComplete'          => $last7dCount >= 7,
                ],
            ];
        })->all();
    }

    private function shopifyRow(int $brandId, string $date): ?DailyMetric
    {
        return DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->where('date', $date)
            ->first();
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
