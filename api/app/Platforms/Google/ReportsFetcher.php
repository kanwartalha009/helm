<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V24\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V24\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V24\Enums\DeviceEnum\Device;
use Illuminate\Support\Facades\Cache;

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
    /**
     * geo_target_constant country id → ISO-2 code.
     *
     * Country criterion ids are global and immutable, so this is cached ACROSS jobs (30 days), not
     * just within one. A job is a single brand-day, so the instance cache alone meant re-buying this
     * ~250-row constant for every brand, every day — over a thousand operations a sync, out of a
     * 15,000/day budget shared by all 200+ brands.
     */
    private const GEO_CACHE_KEY = 'google_ads:geo_country_constants';

    /** Within-run memo, in front of the cross-run cache. */
    private array $geoCountryCache = [];

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

        // Enrichment beyond the core counters: status + channel type (so the UI
        // stops guessing the channel from campaign names), all/view-through
        // conversions, and the Search/Shopping impression-share pair (Google
        // returns them only where they apply; elsewhere they're unset → null).
        $gaql = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, "
            . "customer.currency_code, segments.date, "
            . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, "
            . "metrics.all_conversions, metrics.view_through_conversions, "
            . "metrics.search_impression_share, metrics.search_budget_lost_impression_share "
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
                    'status'           => self::enumToken(CampaignStatus::name($campaign->getStatus())),
                    'channel_type'     => self::enumToken(AdvertisingChannelType::name($campaign->getAdvertisingChannelType())),
                    'spend'            => round(((int) $metrics->getCostMicros()) / 1_000_000, 2),
                    'impressions'      => (int) $metrics->getImpressions(),
                    'clicks'           => (int) $metrics->getClicks(),
                    'conversions'      => (int) round((float) $metrics->getConversions()),
                    'conversion_value' => round((float) $metrics->getConversionsValue(), 2),
                    'all_conversions'  => round((float) $metrics->getAllConversions(), 2),
                    'view_through_conversions' => (int) $metrics->getViewThroughConversions(),
                    // Optional proto fields — the hazzer keeps "not applicable"
                    // (non-Search/Shopping campaigns) as null, never a fake 0.
                    // Google floors sub-10% share at 0.0999.
                    'search_impression_share' => $metrics->hasSearchImpressionShare() ? round((float) $metrics->getSearchImpressionShare(), 4) : null,
                    'search_budget_lost_is'   => $metrics->hasSearchBudgetLostImpressionShare() ? round((float) $metrics->getSearchBudgetLostImpressionShare(), 4) : null,
                    'currency'         => strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $baseCurrency)),
                ];
            }
        }

        return $out;
    }

    /**
     * Daily breakdown for a date range → flat rows for
     * meta_breakdown_daily[platform=google, breakdown_type=$dimension], aggregated
     * per (day, segment) across the brand's customers (native money, or USD when
     * the customers span currencies — same resolveMoney() as the account pull).
     *
     *  - `device`  → segments.device on `campaign` (2→'MOBILE' etc.).
     *  - `country` → geographic_view.country_criterion_id (LOCATION_OF_PRESENCE =
     *    where the buyer physically is, which can differ from the campaign's
     *    target country), resolved to an ISO-2 code via geo_target_constant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBreakdownRange(PlatformConnection $conn, string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! in_array($dimension, ['device', 'country'], true)) {
            return [];
        }
        $customerIds = $this->customerIdsFor($conn);
        if ($customerIds === []) {
            return [];
        }
        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $countryMap   = $dimension === 'country' ? $this->countryConstants($customerIds[0]) : [];

        $gaql = $dimension === 'device'
            ? "SELECT segments.date, segments.device, customer.currency_code, "
                . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value "
                . "FROM campaign WHERE segments.date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}'"
            : "SELECT segments.date, geographic_view.country_criterion_id, customer.currency_code, "
                . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value "
                . "FROM geographic_view WHERE segments.date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}' "
                . "AND geographic_view.location_type = 'LOCATION_OF_PRESENCE'";

        // (date|segment) => running per-currency totals + counts across customers.
        $agg = [];
        foreach ($customerIds as $customerId) {
            foreach ($this->client->search($customerId, $gaql) as $row) {
                $metrics = $row->getMetrics();
                if ($metrics === null) {
                    continue;
                }
                $day = (string) ($row->getSegments()?->getDate() ?? '');
                if ($day === '') {
                    continue;
                }

                if ($dimension === 'device') {
                    // Version-correct enum decode (SDK is V24): 2 → 'MOBILE' etc.
                    $key   = strtolower((string) Device::name($row->getSegments()->getDevice()));
                    $label = $this->deviceLabel($key);
                } else {
                    $cid   = (int) ($row->getGeographicView()?->getCountryCriterionId() ?? 0);
                    $key   = $countryMap[$cid] ?? 'unknown';
                    $label = $key; // ISO-2; the frontend maps it to a country name
                }

                $ccy  = strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $baseCurrency));
                $slot = $day . '|' . $key;

                $agg[$slot] ??= ['date' => $day, 'seg' => $key, 'label' => $label, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0.0, 'spendByCcy' => [], 'valueByCcy' => []];
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
                'segment_key'      => $a['seg'],
                'segment_label'    => $a['label'],
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

    /**
     * Google enum name → stored token: lower-case, with the UNSPECIFIED/UNKNOWN
     * sentinels folded to null so "Google didn't say" reads as missing data,
     * not a value.
     */
    private static function enumToken(string $name): ?string
    {
        $token = strtolower($name);

        return in_array($token, ['unspecified', 'unknown', ''], true) ? null : $token;
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
     * geo_target_constant country_criterion_id → ISO-2 code map (e.g. 2840→'US',
     * 2724→'ES'). ~250 rows, so it's resolved once and cached on the instance for
     * the whole sync/backfill run. Country constants are global; any customer id
     * can query them.
     *
     * @return array<int, string>
     */
    private function countryConstants(string $customerId): array
    {
        if ($this->geoCountryCache !== []) {
            return $this->geoCountryCache;
        }

        // ══ THIS IS A CONSTANT. STOP BUYING IT EVERY TIME. ══
        // The instance cache above only survives one job — and a job is ONE BRAND-DAY. So this
        // ~250-row query was re-issued for every brand, every day, every backfill: well over a
        // thousand operations a sync, spent re-learning that Spain is still 2724.
        //
        // Google Ads Basic access allows 15,000 operations PER DAY across ALL brands. Country
        // criterion ids are global and effectively immutable, so a 30-day cache costs nothing and
        // hands that budget back to the queries that actually carry data.
        $cached = Cache::get(self::GEO_CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $this->geoCountryCache = $cached;
        }

        $gaql = "SELECT geo_target_constant.id, geo_target_constant.country_code "
            . "FROM geo_target_constant WHERE geo_target_constant.target_type = 'Country' "
            . "AND geo_target_constant.status = 'ENABLED'";

        $map = [];
        foreach ($this->client->search($customerId, $gaql) as $row) {
            $g = $row->getGeoTargetConstant();
            if ($g === null) {
                continue;
            }
            $id   = (int) $g->getId();
            $code = strtoupper((string) $g->getCountryCode());
            if ($id > 0 && $code !== '') {
                $map[$id] = $code;
            }
        }

        // Only cache a REAL answer. Caching an empty map because the token was throttled would
        // blank every country label for 30 days — a quota error turning into silent data loss.
        if ($map !== []) {
            Cache::put(self::GEO_CACHE_KEY, $map, now()->addDays(30));
        }

        return $this->geoCountryCache = $map;
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
