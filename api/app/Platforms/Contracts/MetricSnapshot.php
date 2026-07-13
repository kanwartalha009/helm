<?php

declare(strict_types=1);

namespace App\Platforms\Contracts;

use Carbon\CarbonImmutable;

/**
 * Polymorphic DTO returned by PlatformAdapter::fetchDay().
 * Shopify fills the commerce fields and leaves ad fields null.
 * Ad platforms fill spend / impressions / clicks / conversions / conversion_value
 * and leave revenue fields null. Both populate currency and metadata.
 * See spec §6.2.
 */
final class MetricSnapshot
{
    public function __construct(
        public readonly int $brandId,
        public readonly string $platform,
        public readonly CarbonImmutable $date,
        public readonly string $currency,
        public readonly ?float $revenue = null,
        public readonly ?float $revenueNet = null,
        /** Net sales = line items after discounts and returns, excl. shipping/tax/duties (Shopify currentSubtotalPriceSet). */
        public readonly ?float $netSales = null,
        /** Total sales = Shopify's "Total sales" (net sales + shipping + taxes + duties), ShopifyQL total_sales. */
        public readonly ?float $totalSales = null,
        public readonly ?int $orders = null,
        public readonly ?float $refundsAmount = null,
        public readonly ?int $refundedOrders = null,
        public readonly ?float $spend = null,
        public readonly ?int $impressions = null,
        public readonly ?int $clicks = null,
        public readonly ?int $conversions = null,
        public readonly ?float $conversionValue = null,
        public readonly ?array $metadata = null,
        /** Whether the day is fully closed. False for today (partial), true for any past day. */
        public readonly bool $isComplete = true,
        // Meta funnel/efficiency fields (Ads hub). Null on non-Meta snapshots and
        // on rows synced before these columns existed. reach is a daily unique
        // count — a windowed sum is an upper bound; frequency is derived downstream.
        public readonly ?int $reach = null,
        public readonly ?int $linkClicks = null,
        public readonly ?int $landingPageViews = null,
        // Mid-funnel commerce steps (add to cart → checkout started), parsed from
        // Meta's `actions` payload. Nullable for the same reason as reach/linkClicks:
        // rows synced before the columns existed must stay distinguishable from a
        // real zero, so the funnel renders "—", never a fake 0.
        public readonly ?int $addToCarts = null,
        public readonly ?int $checkoutsInitiated = null,
        /**
         * Columns this snapshot must NOT write, because it deliberately did not measure them.
         *
         * `updateableFields()` normally lists every column, so a null on the snapshot OVERWRITES
         * whatever is in the table. That is correct when null means "the platform reported
         * nothing" — but not when it means "we didn't ask". Shopify's fast daily path skips the
         * order-by-order scan (74s on a high-volume brand) and leaves gross `revenue` to the
         * enrichment job; without this list, phase 1 would helpfully erase the number phase 2 just
         * wrote. Missing is not zero, and it is not null-over-a-real-value either.
         *
         * @var array<int, string>
         */
        public readonly array $omitFields = [],
    ) {}

    /**
     * Row shape ready for DailyMetric::upsert(). FX rate is filled at write time.
     *
     * NOTE: `metadata` is returned as a PHP array. Model::upsert() bypasses
     * Eloquent's cast pipeline (the `array`/`jsonb` cast is NOT applied),
     * so the caller MUST json_encode the metadata field before passing
     * the row to upsert(). The sync jobs do this just before the upsert
     * call — see SyncBrandDayJob / SyncBrandHistoryJob.
     */
    public function toRow(?float $fxRateToUsd, bool $fxPending = false): array
    {
        // When the USD rate can't be resolved at sync time we still store the
        // native facts immediately and leave fx_rate_to_usd null, flagging the
        // row so BackfillFxRatesJob fills it once a rate is available. Native
        // figures (revenue, orders, refunds) are never blocked on FX.
        $metadata = $this->metadata;
        if ($fxPending) {
            $metadata ??= [];
            $metadata['fx_pending'] = true;
        }

        return [
            'brand_id'         => $this->brandId,
            'platform'         => $this->platform,
            'date'             => $this->date->toDateString(),
            'revenue'          => $this->revenue,
            'revenue_net'      => $this->revenueNet,
            'net_sales'        => $this->netSales,
            'total_sales'      => $this->totalSales,
            'orders'           => $this->orders,
            'refunds_amount'   => $this->refundsAmount,
            'refunded_orders'  => $this->refundedOrders,
            'spend'            => $this->spend,
            'impressions'      => $this->impressions,
            'clicks'           => $this->clicks,
            'conversions'      => $this->conversions,
            'conversion_value'   => $this->conversionValue,
            'reach'              => $this->reach,
            'link_clicks'        => $this->linkClicks,
            'landing_page_views' => $this->landingPageViews,
            'add_to_carts'        => $this->addToCarts,
            'checkouts_initiated' => $this->checkoutsInitiated,
            'currency'         => $this->currency,
            'fx_rate_to_usd'   => $fxRateToUsd,
            'metadata'         => $metadata,
            'is_complete'      => $this->isComplete,
            'pulled_at'        => now(),
        ];
    }

    /** Columns that get overwritten on upsert. Everything else is set on insert only. */
    public function updateableFields(): array
    {
        $fields = [
            'revenue', 'revenue_net', 'net_sales', 'total_sales', 'orders', 'refunds_amount', 'refunded_orders',
            'spend', 'impressions', 'clicks', 'conversions', 'conversion_value',
            'reach', 'link_clicks', 'landing_page_views', 'add_to_carts', 'checkouts_initiated',
            'currency', 'fx_rate_to_usd', 'metadata', 'is_complete', 'pulled_at',
        ];

        // Columns the snapshot never measured are left ALONE, not overwritten with null.
        return $this->omitFields === []
            ? $fields
            : array_values(array_diff($fields, $this->omitFields));
    }
}
