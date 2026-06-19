<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;

/**
 * Pulls one day of TikTok advertiser-level reporting for a brand and returns a
 * single blended MetricSnapshot (platform = tiktok).
 *
 * A brand stores one TikTok row but may have selected several advertisers under
 * the Business Center (metadata.advertiser_ids). Each advertiser's day is pulled
 * via /report/integrated/get/ and summed into the one row daily_metrics allows
 * per (brand, tiktok, date).
 *
 * Metrics per docs/05-platforms/tiktok.md. We request only the well-defined
 * counters (spend / impressions / clicks / conversion) — `spend` is the figure
 * the dashboard needs and ROAS divides by it. We deliberately omit a revenue
 * metric for now: TikTok's value metric name varies, and requesting an invalid
 * metric makes the whole report call fail. conversion_value can be added once
 * validated against a live BC token (see tiktok:diagnose).
 *
 * Currency: the report doesn't return one, so we stamp the brand's base currency
 * (the advertiser currency normally matches it).
 */
final class ReportsFetcher
{
    public function __construct(
        private readonly TikTokClient $client,
    ) {}

    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        $advertiserIds = $this->advertiserIdsFor($conn);
        $day           = $date->toDateString();
        $tz            = $conn->brand?->timezone ?: 'UTC';
        $isComplete    = $date->startOfDay()->lessThan(CarbonImmutable::now($tz)->startOfDay());
        $currency      = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $brandId       = (int) $conn->brand_id;

        $spend       = 0.0;
        $impressions = 0;
        $clicks      = 0;
        $conversions = 0;

        foreach ($advertiserIds as $advertiserId) {
            $data = $this->client->get('report/integrated/get/', [
                'advertiser_id' => $advertiserId,
                'report_type'   => 'BASIC',
                'data_level'    => 'AUCTION_ADVERTISER',
                'dimensions'    => json_encode(['advertiser_id']),
                'metrics'       => json_encode(['spend', 'impressions', 'clicks', 'conversion']),
                'start_date'    => $day,
                'end_date'      => $day,
                'page'          => 1,
                'page_size'     => 100,
            ]);

            foreach (($data['list'] ?? []) as $row) {
                $m = $row['metrics'] ?? [];
                $spend       += (float) ($m['spend'] ?? 0);
                $impressions += (int) ($m['impressions'] ?? 0);
                $clicks      += (int) ($m['clicks'] ?? 0);
                $conversions += (int) ($m['conversion'] ?? 0);
            }
        }

        return new MetricSnapshot(
            brandId: $brandId,
            platform: 'tiktok',
            date: $date,
            currency: $currency,
            spend: round($spend, 2),
            impressions: $impressions,
            clicks: $clicks,
            conversions: $conversions,
            conversionValue: 0.0,
            metadata: ['advertiser_ids' => array_values($advertiserIds)],
            isComplete: $isComplete,
        );
    }

    /**
     * The advertiser IDs to pull for this brand: the selected list when present,
     * otherwise the single external_id.
     *
     * @return array<int, string>
     */
    private function advertiserIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['advertiser_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            return array_values(array_map(static fn ($i) => (string) $i, $ids));
        }

        return $conn->external_id ? [(string) $conn->external_id] : [];
    }
}
