<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\DailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Support\AdAudit;
use App\Reports\Support\DeadInventory;
use Carbon\CarbonImmutable;

/**
 * THE privacy boundary for D-016 (ratified 2026-07-10, aggregates only).
 *
 * This is the single builder of everything an LLM is allowed to see, and it
 * is read-only by construction: it queries aggregate tables (daily_metrics,
 * commerce_daily_metrics, ad_campaign_daily_metrics via AdAudit,
 * inventory_snapshots via DeadInventory) and emits a compact array of sums,
 * series and names. What can appear: the brand's name/currency/timezone,
 * dated aggregate metrics, campaign/creative/product/country NAMES with
 * their aggregate metrics. What can never appear: customer rows, emails,
 * order ids, addresses, tokens, user accounts — none are queried, and
 * Helm's schema holds no customer PII to begin with.
 *
 * Both LLM surfaces (report narrative + chat) consume this payload and
 * nothing else. The privacy test (LlmScopeTest) asserts the payload's key
 * surface stays inside the allowlist, so a future field addition that
 * widens the scope fails CI instead of shipping silently.
 */
final class BrandDataScope
{
    public function __construct(
        private readonly AdAudit $ads,
        private readonly DeadInventory $inventory,
    ) {}

    /**
     * @return array<string, mixed> compact, JSON-serialisable aggregate payload
     */
    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        [$start, $end]   = $filters->window($tz);
        [$cStart, $cEnd] = $filters->comparisonWindow($tz);

        return [
            'brand' => [
                'name'     => $brand->name,
                'currency' => $brand->base_currency,
                'timezone' => $tz,
            ],
            'period' => [
                'label' => $filters->periodLabel(),
                'start' => $start,
                'end'   => $end,
                'comparison' => $filters->comparisonLabel(),
            ],
            'totals'     => $this->totals($brand->id, $start, $end),
            'priorTotals' => ($cStart && $cEnd) ? $this->totals($brand->id, $cStart, $cEnd) : null,
            // Daily revenue/spend series — the shape the narrative reasons about.
            'dailySeries' => $this->series($brand->id, $start, $end),
            // Top commerce splits (names + aggregate metrics only).
            'topProducts'  => $this->commerceTop($brand->id, 'product', $start, $end),
            'topCountries' => $this->commerceTop($brand->id, 'country', $start, $end),
            'topCategories' => $this->commerceTop($brand->id, 'category', $start, $end),
            // Campaign-level audit per ad platform (existing rules engine —
            // verdicts and waste are RULES output; the LLM narrates, never scores).
            'adsAudit' => $this->adsAudit($brand, $start, $end, $cStart, $cEnd),
            // Dead/slow stock from the latest snapshot (existing rules engine).
            'deadInventory' => $this->inventory->forDimension($brand->id, 'product', 8),
        ];
    }

    /** @return array<string, mixed> */
    private function totals(int $brandId, string $start, string $end): array
    {
        $shop = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw(
                'COALESCE(SUM(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)), 0) as total_revenue,'
                . 'COALESCE(SUM(COALESCE(net_sales, 0)), 0)      as net_sales,'
                . 'COALESCE(SUM(COALESCE(refunds_amount, 0)), 0) as refunds,'
                . 'COALESCE(SUM(COALESCE(orders, 0)), 0)         as orders,'
                . 'COUNT(*)                                      as days_on_file,'
                . 'SUM(CASE WHEN is_complete THEN 0 ELSE 1 END)  as incomplete_days'
            )->first();

        $spendByPlatform = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', ['meta', 'google', 'tiktok'])
            ->whereBetween('date', [$start, $end])
            ->groupBy('platform')
            ->selectRaw('platform, COALESCE(SUM(COALESCE(spend, 0)), 0) as spend')
            ->pluck('spend', 'platform')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        $revenue = round((float) ($shop->total_revenue ?? 0), 2);
        $spend   = round(array_sum($spendByPlatform), 2);
        $orders  = (int) ($shop->orders ?? 0);

        return [
            'totalRevenue'    => $revenue,
            'netSales'        => round((float) ($shop->net_sales ?? 0), 2),
            'refunds'         => round((float) ($shop->refunds ?? 0), 2),
            'orders'          => $orders,
            'aov'             => $orders > 0 ? round($revenue / $orders, 2) : null,
            'adSpend'         => $spend > 0 ? $spend : null,
            'adSpendByPlatform' => $spendByPlatform ?: null,
            'blendedRoas'     => $spend > 0 ? round($revenue / $spend, 2) : null,
            'daysOnFile'      => (int) ($shop->days_on_file ?? 0),
            'incompleteDays'  => (int) ($shop->incomplete_days ?? 0),
        ];
    }

    /**
     * One row per day: date, revenue, spend. Compact — 7/30/90 rows.
     *
     * @return array<int, array{date: string, revenue: ?float, spend: ?float}>
     */
    private function series(int $brandId, string $start, string $end): array
    {
        $rows = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw(
                'date,'
                . "COALESCE(SUM(CASE WHEN platform = 'shopify' THEN COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0) ELSE 0 END), 0) as revenue,"
                . "COALESCE(SUM(CASE WHEN platform <> 'shopify' THEN COALESCE(spend, 0) ELSE 0 END), 0) as spend,"
                . "MAX(CASE WHEN platform = 'shopify' AND is_complete THEN 1 ELSE 0 END) as complete"
            )
            ->get();

        return $rows->map(fn ($r) => [
            'date'     => CarbonImmutable::parse((string) $r->date)->toDateString(),
            'revenue'  => ((int) $r->complete) === 1 ? round((float) $r->revenue, 2) : null, // partial day → null, never a half number
            'spend'    => round((float) $r->spend, 2),
        ])->all();
    }

    /**
     * Top rows for one commerce dimension: name + aggregate metrics only.
     *
     * @return array<int, array<string, mixed>>|null null when the dimension has no rows
     */
    private function commerceTop(int $brandId, string $dimension, string $start, string $end, int $limit = 8): ?array
    {
        $rows = CommerceDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', $dimension)
            ->whereBetween('date', [$start, $end])
            ->groupBy('dimension_key', 'dimension_label')
            ->selectRaw(
                'dimension_key, MAX(dimension_label) as label,'
                . 'COALESCE(SUM(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)), 0) as revenue,'
                . 'COALESCE(SUM(COALESCE(orders, 0)), 0) as orders,'
                . 'COALESCE(SUM(COALESCE(units, 0)), 0)  as units,'
                . 'COALESCE(SUM(COALESCE(refunds_amount, 0)), 0) as refunds'
            )
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(fn ($r) => [
            'name'    => (string) ($r->label ?: $r->dimension_key),
            'revenue' => round((float) $r->revenue, 2),
            'orders'  => (int) $r->orders,
            'units'   => (int) $r->units,
            'refunds' => round((float) $r->refunds, 2),
        ])->all();
    }

    /** @return array<int, mixed> */
    private function adsAudit(Brand $brand, string $start, string $end, ?string $cStart, ?string $cEnd): array
    {
        $connected = $brand->connections()
            ->where('status', 'active')
            ->pluck('platform')
            ->all();

        $out = [];
        foreach (['meta', 'google', 'tiktok'] as $platform) {
            if (! in_array($platform, $connected, true)) {
                continue;
            }
            try {
                $audit = $this->ads->forPlatform($brand->id, $platform, $start, $end, $cStart, $cEnd, usd: false);
            } catch (\Throwable) {
                continue; // scope building must never fail the caller
            }
            if ($audit !== null) {
                $out[] = $audit;
            }
        }

        return $out;
    }
}
