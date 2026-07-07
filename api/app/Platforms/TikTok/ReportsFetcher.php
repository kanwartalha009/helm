<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pulls one day of TikTok advertiser-level reporting for a brand and returns a
 * single blended MetricSnapshot (platform = tiktok).
 *
 * A brand stores one TikTok row but may have selected several advertisers under
 * the Business Center (metadata.advertiser_ids). Each advertiser's day is pulled
 * via /report/integrated/get/ and summed into the one row daily_metrics allows
 * per (brand, tiktok, date).
 *
 * Metrics per docs/05-platforms/tiktok.md. We request the counters (spend /
 * impressions / clicks) plus purchases + purchase VALUE so ROAS / Revenue / AOV
 * work. The purchase + value metric names are config-driven
 * (services.tiktok.purchase_metric / value_metric, default complete_payment /
 * total_complete_payment) because TikTok's value-metric name varies by account.
 * Since an UNKNOWN metric fails the whole report call, reportRows() tries the
 * rich set first and falls back to the base counters — the daily sync never
 * breaks on a bad name (it just reverts to spend-side only until the name is
 * fixed). Validate the names for a live account with tiktok:diagnose.
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

        $purchaseMetric = (string) config('services.tiktok.purchase_metric', 'complete_payment');
        $valueMetric    = (string) config('services.tiktok.value_metric', 'total_complete_payment');

        $spend       = 0.0;
        $impressions = 0;
        $clicks      = 0;
        $conversions = 0;
        $value       = 0.0;

        foreach ($advertiserIds as $advertiserId) {
            foreach ($this->reportRows($advertiserId, $day, $purchaseMetric, $valueMetric) as $row) {
                $m = $row['metrics'] ?? [];
                $spend       += (float) ($m['spend'] ?? 0);
                $impressions += (int) ($m['impressions'] ?? 0);
                $clicks      += (int) ($m['clicks'] ?? 0);
                // Purchases: the payment-specific metric when present, else the
                // generic conversion count (base-set fallback).
                $conversions += (int) round((float) ($m[$purchaseMetric] ?? $m['conversion'] ?? 0));
                $value       += (float) ($m[$valueMetric] ?? 0);
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
            conversionValue: round($value, 2),
            metadata: ['advertiser_ids' => array_values($advertiserIds)],
            isComplete: $isComplete,
        );
    }

    /**
     * One advertiser-day's report rows. Tries the rich metric set (adds purchases
     * + purchase value) first; if TikTok rejects an unknown metric — which fails
     * the WHOLE call — it retries with the base counters so the daily sync keeps
     * working (spend-side only) instead of erroring the whole brand-day.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reportRows(string $advertiserId, string $day, string $purchaseMetric, string $valueMetric): array
    {
        $base = ['spend', 'impressions', 'clicks', 'conversion'];
        $rich = array_values(array_unique([...$base, $purchaseMetric, $valueMetric]));

        foreach ([$rich, $base] as $metrics) {
            try {
                $data = $this->client->get('report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type'   => 'BASIC',
                    'data_level'    => 'AUCTION_ADVERTISER',
                    'dimensions'    => json_encode(['advertiser_id']),
                    'metrics'       => json_encode($metrics),
                    'start_date'    => $day,
                    'end_date'      => $day,
                    'page'          => 1,
                    'page_size'     => 100,
                ]);

                return $data['list'] ?? [];
            } catch (RuntimeException $e) {
                if ($metrics === $base) {
                    throw $e; // the base set failing is a real error — surface it
                }
                Log::warning('tiktok.report.metric_fallback', [
                    'advertiser' => $advertiserId,
                    'metrics'    => $metrics,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return [];
    }

    /**
     * Campaign-level daily rows over [from, to] for the Ads hub's Campaign
     * analysis (ad_campaign_daily_metrics[tiktok]). One row per (campaign, day)
     * via the stat_time_day dimension, so the caller can upsert per day. Same
     * shape the Meta/Google fetchCampaignRange return, so CampaignSync +
     * ads:backfill-campaigns treat all three identically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCampaignRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $advertiserIds = $this->advertiserIdsFor($conn);
        if ($advertiserIds === []) {
            return [];
        }
        $currency       = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $purchaseMetric = (string) config('services.tiktok.purchase_metric', 'complete_payment');
        $valueMetric    = (string) config('services.tiktok.value_metric', 'total_complete_payment');

        $out = [];
        foreach ($advertiserIds as $advertiserId) {
            foreach ($this->campaignRows($advertiserId, $from->toDateString(), $to->toDateString(), $purchaseMetric, $valueMetric) as $row) {
                $dims = $row['dimensions'] ?? [];
                $m    = $row['metrics'] ?? [];
                $cid  = (string) ($dims['campaign_id'] ?? '');
                $day  = substr((string) ($dims['stat_time_day'] ?? ''), 0, 10);
                if ($cid === '' || $day === '') {
                    continue;
                }

                $out[] = [
                    'date'             => $day,
                    'campaign_id'      => $cid,
                    'campaign_name'    => (string) ($m['campaign_name'] ?? ''),
                    'spend'            => (float) ($m['spend'] ?? 0),
                    'impressions'      => (int) ($m['impressions'] ?? 0),
                    'clicks'           => (int) ($m['clicks'] ?? 0),
                    'conversions'      => (int) round((float) ($m[$purchaseMetric] ?? $m['conversion'] ?? 0)),
                    'conversion_value' => (float) ($m[$valueMetric] ?? 0),
                    'currency'         => $currency,
                ];
            }
        }

        return $out;
    }

    /**
     * Paged campaign-day rows for one advertiser, with the rich→base metric
     * fallback (campaign_name/spend/impressions/clicks/conversion always survive;
     * purchases + value drop out only if their metric name is unknown), so a bad
     * value-metric name never fails the whole campaign pull.
     *
     * @return array<int, array<string, mixed>>
     */
    private function campaignRows(string $advertiserId, string $from, string $to, string $purchaseMetric, string $valueMetric): array
    {
        $base = ['campaign_name', 'spend', 'impressions', 'clicks', 'conversion'];
        $rich = array_values(array_unique([...$base, $purchaseMetric, $valueMetric]));

        foreach ([$rich, $base] as $metrics) {
            try {
                return $this->client->paged('report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type'   => 'BASIC',
                    'data_level'    => 'AUCTION_CAMPAIGN',
                    'dimensions'    => json_encode(['campaign_id', 'stat_time_day']),
                    'metrics'       => json_encode($metrics),
                    'start_date'    => $from,
                    'end_date'      => $to,
                ]);
            } catch (RuntimeException $e) {
                if ($metrics === $base) {
                    throw $e;
                }
                Log::warning('tiktok.campaign.metric_fallback', ['advertiser' => $advertiserId, 'error' => $e->getMessage()]);
            }
        }

        return [];
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
