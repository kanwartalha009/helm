<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V24\Enums\DeviceEnum\Device;

/**
 * Pulls one day of Google Ads reporting metrics for a brand's connection and
 * returns a single blended MetricSnapshot (platform = google).
 *
 * Mirrors the Meta InsightsFetcher: a brand stores one Google row but may have
 * selected one or more customer accounts under the MCC
 * (metadata.customer_ids). Each customer's day is pulled via GAQL, then blended
 * into the one row daily_metrics allows per (brand, google, date): counts sum;
 * money sums natively when every account shares a currency, else each is
 * converted to USD first and the row is stamped USD.
 *
 * GAQL fields per docs/05-platforms/google.md. cost_micros is millionths of the
 * account currency, so we divide by 1,000,000.
 */
final class ReportsFetcher
{
    public function __construct(
        private readonly GoogleAdsClient $client,
        private readonly FxService $fx,
    ) {}

    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        $customerIds  = $this->customerIdsFor($conn);
        $day          = $date->toDateString();
        $tz           = $conn->brand?->timezone ?: 'UTC';
        $isComplete   = $date->startOfDay()->lessThan(CarbonImmutable::now($tz)->startOfDay());
        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $brandId      = (int) $conn->brand_id;

        if ($customerIds === []) {
            // No customer selected yet — store a real zero row in the brand
            // currency so the sync succeeds and the dashboard shows 0, not a fail.
            return $this->snapshot($brandId, $date, $baseCurrency, 0.0, 0, 0, 0, 0.0, [], $isComplete);
        }

        // Account-level metrics for the day. FROM customer with segments.date
        // yields one row per (customer, date).
        $gaql = "SELECT customer.id, customer.currency_code, metrics.cost_micros, "
            . "metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value "
            . "FROM customer WHERE segments.date = '{$day}'";

        $impressions = 0;
        $clicks      = 0;
        $conversions = 0.0;
        $spendByCcy  = [];
        $valueByCcy  = [];

        foreach ($customerIds as $customerId) {
            foreach ($this->client->search($customerId, $gaql) as $row) {
                $metrics = $row->getMetrics();
                if ($metrics === null) {
                    continue;
                }
                $currency = strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $baseCurrency));

                $impressions += (int) $metrics->getImpressions();
                $clicks      += (int) $metrics->getClicks();
                $conversions += (float) $metrics->getConversions();

                $spendByCcy[$currency] = ($spendByCcy[$currency] ?? 0.0) + ((int) $metrics->getCostMicros()) / 1_000_000;
                $valueByCcy[$currency] = ($valueByCcy[$currency] ?? 0.0) + (float) $metrics->getConversionsValue();
            }
        }

        [$currency, $spend, $value] = $this->resolveMoney($spendByCcy, $valueByCcy, $baseCurrency, $date);

        return $this->snapshot(
            $brandId,
            $date,
            $currency,
            $spend,
            $impressions,
            $clicks,
            (int) round($conversions),
            $value,
            array_values($customerIds),
            $isComplete,
        );
    }

    /**
     * Daily account-level metrics for a DATE RANGE — one GAQL call per customer
     * (segments.date BETWEEN → one row per (customer, day)), blended per day.
     * Powers the historical spend backfill (`ads:backfill-spend`) the YoY
     * spend/ROAS comparison needs. Same fields as fetch() plus segments.date.
     *
     * @return array<string, MetricSnapshot> keyed by Y-m-d, ascending
     */
    public function fetchRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $customerIds = $this->customerIdsFor($conn);
        if ($customerIds === []) {
            return [];
        }

        $tz           = $conn->brand?->timezone ?: 'UTC';
        $today        = CarbonImmutable::now($tz)->startOfDay();
        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $brandId      = (int) $conn->brand_id;

        $gaql = "SELECT customer.id, customer.currency_code, segments.date, metrics.cost_micros, "
            . "metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value "
            . "FROM customer WHERE segments.date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}'";

        // Y-m-d => running per-currency totals for that day across customers.
        $perDay = [];
        foreach ($customerIds as $customerId) {
            foreach ($this->client->search($customerId, $gaql) as $row) {
                $metrics  = $row->getMetrics();
                $segments = $row->getSegments();
                if ($metrics === null || $segments === null) {
                    continue;
                }
                $day = (string) $segments->getDate();
                if ($day === '') {
                    continue;
                }
                $currency = strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $baseCurrency));

                $perDay[$day] ??= ['spendByCcy' => [], 'valueByCcy' => [], 'impressions' => 0, 'clicks' => 0, 'conversions' => 0.0];
                $perDay[$day]['impressions'] += (int) $metrics->getImpressions();
                $perDay[$day]['clicks']      += (int) $metrics->getClicks();
                $perDay[$day]['conversions'] += (float) $metrics->getConversions();
                $perDay[$day]['spendByCcy'][$currency] = ($perDay[$day]['spendByCcy'][$currency] ?? 0.0) + ((int) $metrics->getCostMicros()) / 1_000_000;
                $perDay[$day]['valueByCcy'][$currency] = ($perDay[$day]['valueByCcy'][$currency] ?? 0.0) + (float) $metrics->getConversionsValue();
            }
        }

        $out = [];
        foreach ($perDay as $day => $agg) {
            $date = CarbonImmutable::parse($day, $tz)->startOfDay();
            [$currency, $spend, $value] = $this->resolveMoney($agg['spendByCcy'], $agg['valueByCcy'], $baseCurrency, $date);
            $out[$day] = $this->snapshot(
                $brandId,
                $date,
                $currency,
                $spend,
                $agg['impressions'],
                $agg['clicks'],
                (int) round($agg['conversions']),
                $value,
                array_values($customerIds),
                $date->lessThan($today),
            );
        }
        ksort($out);

        return $out;
    }

    /**
     * Daily CAMPAIGN-level metrics for a date range — GAQL `FROM campaign` with
     * segments.date yields one row per (campaign, day). Powers the ads audit
     * (slice 2.4): spend / conversions / ROAS per campaign. Returns flat
     * native-currency rows; the backfill stamps fx and upserts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCampaignRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $customerIds = $this->customerIdsFor($conn);
        if ($customerIds === []) {
            return [];
        }

        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $gaql = "SELECT campaign.id, campaign.name, customer.currency_code, segments.date, "
            . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value "
            . "FROM campaign WHERE segments.date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}'";

        $out = [];
        foreach ($customerIds as $customerId) {
            foreach ($this->client->search($customerId, $gaql) as $row) {
                $metrics  = $row->getMetrics();
                $segments = $row->getSegments();
                $campaign = $row->getCampaign();
                if ($metrics === null || $segments === null || $campaign === null) {
                    continue;
                }
                $day = (string) $segments->getDate();
                $cid = (string) $campaign->getId();
                if ($day === '' || $cid === '') {
                    continue;
                }

                $out[] = [
                    'date'             => $day,
                    'campaign_id'      => $cid,
                    'campaign_name'    => (string) $campaign->getName(),
                    'spend'            => round(((int) $metrics->getCostMicros()) / 1_000_000, 2),
                    'impressions'      => (int) $metrics->getImpressions(),
                    'clicks'           => (int) $metrics->getClicks(),
                    'conversions'      => (int) round((float) $metrics->getConversions()),
                    'conversion_value' => round((float) $metrics->getConversionsValue(), 2),
                    'currency'         => strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $baseCurrency)),
                ];
            }
        }

        return $out;
    }

    /**
     * Daily DEVICE breakdown for a date range → flat rows for
     * meta_breakdown_daily[platform=google, breakdown_type=device]. GAQL from
     * `campaign` with segments.device + segments.date, aggregated per (day,
     * device) across the brand's customers (native money, or USD when the
     * customers span currencies — same resolveMoney() as the account pull). Powers
     * the Google Overview's device donut/detail.
     *
     * Only `device` is implemented. Geo is deliberately NOT built: for accounts
     * running per-country campaigns (the norm here) a country breakdown duplicates
     * the campaign table, and it would need geo_target_constant name resolution —
     * cost without value. Add a 'country' branch only if a buyer runs single
     * global campaigns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBreakdownRange(PlatformConnection $conn, string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($dimension !== 'device') {
            return [];
        }
        $customerIds = $this->customerIdsFor($conn);
        if ($customerIds === []) {
            return [];
        }
        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $gaql = "SELECT segments.date, segments.device, customer.currency_code, "
            . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value "
            . "FROM campaign WHERE segments.date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}'";

        // (date|device) => running per-currency totals + counts across customers.
        $agg = [];
        foreach ($customerIds as $customerId) {
            foreach ($this->client->search($customerId, $gaql) as $row) {
                $metrics  = $row->getMetrics();
                $segments = $row->getSegments();
                if ($metrics === null || $segments === null) {
                    continue;
                }
                $day = (string) $segments->getDate();
                if ($day === '') {
                    continue;
                }
                // Version-correct enum decode (SDK is V24): 2 → 'MOBILE' etc.
                $device = strtolower((string) Device::name($segments->getDevice()));
                $ccy    = strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $baseCurrency));
                $slot   = $day . '|' . $device;

                $agg[$slot] ??= ['date' => $day, 'device' => $device, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0.0, 'spendByCcy' => [], 'valueByCcy' => []];
                $agg[$slot]['impressions'] += (int) $metrics->getImpressions();
                $agg[$slot]['clicks']      += (int) $metrics->getClicks();
                $agg[$slot]['conversions'] += (float) $metrics->getConversions();
                $agg[$slot]['spendByCcy'][$ccy] = ($agg[$slot]['spendByCcy'][$ccy] ?? 0.0) + ((int) $metrics->getCostMicros()) / 1_000_000;
                $agg[$slot]['valueByCcy'][$ccy] = ($agg[$slot]['valueByCcy'][$ccy] ?? 0.0) + (float) $metrics->getConversionsValue();
            }
        }

        $out = [];
        foreach ($agg as $a) {
            $date = CarbonImmutable::parse($a['date']);
            [$ccy, $spend, $value] = $this->resolveMoney($a['spendByCcy'], $a['valueByCcy'], $baseCurrency, $date);
            $out[] = [
                'date'             => $a['date'],
                'segment_key'      => $a['device'],
                'segment_label'    => $this->deviceLabel($a['device']),
                'spend'            => round($spend, 2),
                'impressions'      => $a['impressions'],
                'clicks'           => $a['clicks'],
                'conversions'      => (int) round($a['conversions']),
                'conversion_value' => round($value, 2),
                'currency'         => $ccy,
            ];
        }

        return $out;
    }

    /** Google Device enum name (lower-case) → display label. */
    private function deviceLabel(string $key): string
    {
        return match ($key) {
            'mobile'       => 'Mobile',
            'desktop'      => 'Desktop',
            'tablet'       => 'Tablet',
            'connected_tv' => 'Connected TV',
            default        => ucfirst(str_replace('_', ' ', $key)), // other / unknown
        };
    }

    /**
     * The customer IDs to pull for this brand: the selected list when present,
     * otherwise the single external_id.
     *
     * @return array<int, string>
     */
    private function customerIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['customer_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            return array_values(array_map(static fn ($i) => (string) $i, $ids));
        }

        return $conn->external_id ? [(string) $conn->external_id] : [];
    }

    /**
     * Collapse per-currency spend/value into one figure: native when every
     * account shares a currency, else convert each to USD and stamp USD.
     *
     * @param array<string, float> $spendByCcy
     * @param array<string, float> $valueByCcy
     * @return array{0: string, 1: float, 2: float}
     */
    private function resolveMoney(array $spendByCcy, array $valueByCcy, string $baseCurrency, CarbonImmutable $date): array
    {
        $currencies = array_values(array_unique(array_merge(array_keys($spendByCcy), array_keys($valueByCcy))));

        if ($currencies === []) {
            return [$baseCurrency, 0.0, 0.0];
        }
        if (count($currencies) === 1) {
            $ccy = $currencies[0];
            return [$ccy, $spendByCcy[$ccy] ?? 0.0, $valueByCcy[$ccy] ?? 0.0];
        }

        $spend = 0.0;
        $value = 0.0;
        foreach ($currencies as $ccy) {
            $rate   = $this->fx->toUsd($ccy, $date);
            $spend += ($spendByCcy[$ccy] ?? 0.0) * $rate;
            $value += ($valueByCcy[$ccy] ?? 0.0) * $rate;
        }

        return ['USD', $spend, $value];
    }

    /**
     * @param array<int, string> $customerIds
     */
    private function snapshot(
        int $brandId,
        CarbonImmutable $date,
        string $currency,
        float $spend,
        int $impressions,
        int $clicks,
        int $conversions,
        float $conversionValue,
        array $customerIds,
        bool $isComplete,
    ): MetricSnapshot {
        return new MetricSnapshot(
            brandId: $brandId,
            platform: 'google',
            date: $date,
            currency: $currency,
            spend: round($spend, 2),
            impressions: $impressions,
            clicks: $clicks,
            conversions: $conversions,
            conversionValue: round($conversionValue, 2),
            metadata: ['customer_ids' => $customerIds],
            isComplete: $isComplete,
        );
    }
}
