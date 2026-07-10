<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V24\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V24\Enums\AssetGroupStatusEnum\AssetGroupStatus;

/**
 * Google ad-GROUP + PMax asset-group level metrics (spec §4 Phase 3b). Mirrors
 * ReportsFetcher::fetchCampaignRange. TWO GAQL queries per customer: `ad_group`
 * for normal campaigns, `asset_group` for Performance Max — which has NO ad groups
 * (Google reports it at asset-group level, with no budget of its own; entity_kind
 * = 'asset_group'). Returns flat NATIVE rows (cost_micros ÷ 1e6); the sync stamps
 * fx. Impression-share metrics are Search-only → null elsewhere, never 0.
 *
 * V24 field/enum availability should be verified against the pinned google-ads-php
 * (^33.3 = API V24) on the server, per spec §2.3.
 */
class AdGroupFetcher
{
    public function __construct(private readonly GoogleAdsClient $client) {}

    /** @return array<int, array<string, mixed>> */
    public function fetchRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $customerIds = $this->customerIdsFor($conn);
        if ($customerIds === []) {
            return [];
        }
        $base  = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $since = $from->toDateString();
        $until = $to->toDateString();

        $adGroupGaql = 'SELECT ad_group.id, ad_group.name, ad_group.status, campaign.id, '
            . 'customer.currency_code, segments.date, '
            . 'metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, '
            . 'metrics.search_impression_share, metrics.search_budget_lost_impression_share '
            . "FROM ad_group WHERE segments.date BETWEEN '{$since}' AND '{$until}' AND ad_group.status != 'REMOVED'";

        $assetGroupGaql = 'SELECT asset_group.id, asset_group.name, asset_group.status, campaign.id, '
            . 'customer.currency_code, segments.date, '
            . 'metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value '
            . "FROM asset_group WHERE segments.date BETWEEN '{$since}' AND '{$until}'";

        $out = [];
        foreach ($customerIds as $customerId) {
            foreach ($this->client->search($customerId, $adGroupGaql) as $row) {
                $m   = $row->getMetrics();
                $seg = $row->getSegments();
                $ag  = $row->getAdGroup();
                if ($m === null || $seg === null || $ag === null) {
                    continue;
                }
                $day = (string) $seg->getDate();
                $id  = (string) $ag->getId();
                if ($day === '' || $id === '') {
                    continue;
                }
                $out[] = [
                    'date'                    => $day,
                    'ad_set_id'               => $id,
                    'ad_set_name'             => (string) $ag->getName(),
                    'campaign_id'             => $row->getCampaign() !== null ? (string) $row->getCampaign()->getId() : null,
                    'entity_kind'             => 'ad_set',
                    'status'                  => self::enumToken(AdGroupStatus::name($ag->getStatus())),
                    'spend'                   => round(((int) $m->getCostMicros()) / 1_000_000, 2),
                    'impressions'             => (int) $m->getImpressions(),
                    'clicks'                  => (int) $m->getClicks(),
                    'conversions'             => (int) round((float) $m->getConversions()),
                    'conversion_value'        => round((float) $m->getConversionsValue(), 2),
                    'search_impression_share' => $m->hasSearchImpressionShare() ? round((float) $m->getSearchImpressionShare(), 4) : null,
                    'search_budget_lost_is'   => $m->hasSearchBudgetLostImpressionShare() ? round((float) $m->getSearchBudgetLostImpressionShare(), 4) : null,
                    'currency'                => strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $base)),
                ];
            }

            foreach ($this->client->search($customerId, $assetGroupGaql) as $row) {
                $m   = $row->getMetrics();
                $seg = $row->getSegments();
                $ag  = $row->getAssetGroup();
                if ($m === null || $seg === null || $ag === null) {
                    continue;
                }
                $day = (string) $seg->getDate();
                $id  = (string) $ag->getId();
                if ($day === '' || $id === '') {
                    continue;
                }
                $out[] = [
                    'date'             => $day,
                    'ad_set_id'        => $id,
                    'ad_set_name'      => (string) $ag->getName(),
                    'campaign_id'      => $row->getCampaign() !== null ? (string) $row->getCampaign()->getId() : null,
                    'entity_kind'      => 'asset_group', // PMax has no ad groups
                    'status'           => self::enumToken(AssetGroupStatus::name($ag->getStatus())),
                    'spend'            => round(((int) $m->getCostMicros()) / 1_000_000, 2),
                    'impressions'      => (int) $m->getImpressions(),
                    'clicks'           => (int) $m->getClicks(),
                    'conversions'      => (int) round((float) $m->getConversions()),
                    'conversion_value' => round((float) $m->getConversionsValue(), 2),
                    'currency'         => strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $base)),
                ];
            }
        }

        return $out;
    }

    private static function enumToken(string $name): ?string
    {
        $token = strtolower($name);

        return in_array($token, ['unspecified', 'unknown', ''], true) ? null : $token;
    }

    /** @return array<int, string> */
    private function customerIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['customer_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            return array_values(array_map(static fn ($i) => (string) $i, $ids));
        }

        return $conn->external_id ? [(string) $conn->external_id] : [];
    }
}
