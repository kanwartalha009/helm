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
            'orders'           => $this->orders,
            'refunds_amount'   => $this->refundsAmount,
            'refunded_orders'  => $this->refundedOrders,
            'spend'            => $this->spend,
            'impressions'      => $this->impressions,
            'clicks'           => $this->clicks,
            'conversions'      => $this->conversions,
            'conversion_value' => $this->conversionValue,
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
        return [
            'revenue', 'revenue_net', 'orders', 'refunds_amount', 'refunded_orders',
            'spend', 'impressions', 'clicks', 'conversions', 'conversion_value',
            'currency', 'fx_rate_to_usd', 'metadata', 'is_complete', 'pulled_at',
        ];
    }
}
