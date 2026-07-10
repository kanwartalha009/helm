<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * TikTok ad-GROUP level metrics + entity snapshot (spec §4 Phase 3b). Mirrors
 * ReportsFetcher::fetchCampaignRange with data_level=AUCTION_ADGROUP and the same
 * rich→base metric fallback (so a bad value-metric name never fails the pull),
 * plus an adgroup/get entity call for budget + status. Returns flat NATIVE rows;
 * the sync stamps fx. TikTok exposes no reach/frequency here → null (never 0);
 * entity_kind is always 'ad_set'.
 *
 * Verify against a live BC (tiktok:diagnose): advertiser-id source + adgroup/get
 * field names, per spec §2.3/§6.2.
 */
final class AdGroupFetcher
{
    public function __construct(private readonly TikTokClient $client) {}

    /** @return array<int, array<string, mixed>> */
    public function fetchRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $advertiserIds = $this->advertiserIdsFor($conn);
        if ($advertiserIds === []) {
            return [];
        }
        $currency       = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $purchaseMetric = (string) config('services.tiktok.purchase_metric', 'complete_payment');
        $valueMetric    = (string) config('services.tiktok.value_metric', 'value_per_complete_payment');
        $perPurchase    = config('services.tiktok.value_metric_kind', 'per_purchase') === 'per_purchase';

        $out = [];
        foreach ($advertiserIds as $advertiserId) {
            $entity = $this->fetchEntities($advertiserId); // adgroup_id => budget/status snapshot
            foreach ($this->adGroupRows($advertiserId, $from->toDateString(), $to->toDateString(), $purchaseMetric, $valueMetric) as $row) {
                $dims = $row['dimensions'] ?? [];
                $m    = $row['metrics'] ?? [];
                $gid  = (string) ($dims['adgroup_id'] ?? '');
                $day  = substr((string) ($dims['stat_time_day'] ?? ''), 0, 10);
                if ($gid === '' || $day === '') {
                    continue;
                }
                $purch = (int) round((float) ($m[$purchaseMetric] ?? $m['conversion'] ?? 0));
                $val   = (float) ($m[$valueMetric] ?? 0);
                $e     = $entity[$gid] ?? [];
                $out[] = [
                    'date'             => $day,
                    'ad_set_id'        => $gid,
                    'ad_set_name'      => (string) ($m['adgroup_name'] ?? ($e['name'] ?? '')),
                    'campaign_id'      => $e['campaign_id'] ?? null,
                    'entity_kind'      => 'ad_set',
                    'spend'            => (float) ($m['spend'] ?? 0),
                    'impressions'      => (int) ($m['impressions'] ?? 0),
                    'clicks'           => (int) ($m['clicks'] ?? 0),
                    'reach'            => null,
                    'frequency'        => null,
                    'conversions'      => $purch,
                    'conversion_value' => $perPurchase ? $val * $purch : $val,
                    'currency'         => $currency,
                    'status'           => $e['status'] ?? null,
                    'daily_budget'     => $e['daily_budget'] ?? null,
                    'lifetime_budget'  => $e['lifetime_budget'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * Paged ad-group-day rows for one advertiser with the rich→base fallback
     * (adgroup_name/spend/impressions/clicks/conversion always survive; purchases +
     * value drop out only if their metric name is unknown).
     *
     * @return array<int, array<string, mixed>>
     */
    private function adGroupRows(string $advertiserId, string $from, string $to, string $purchaseMetric, string $valueMetric): array
    {
        $base = ['adgroup_name', 'spend', 'impressions', 'clicks', 'conversion'];
        $rich = array_values(array_unique([...$base, $purchaseMetric, $valueMetric]));

        foreach ([$rich, $base] as $metrics) {
            try {
                return $this->client->paged('report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type'   => 'BASIC',
                    'data_level'    => 'AUCTION_ADGROUP',
                    'dimensions'    => json_encode(['adgroup_id', 'stat_time_day']),
                    'metrics'       => json_encode($metrics),
                    'start_date'    => $from,
                    'end_date'      => $to,
                ]);
            } catch (RuntimeException $e) {
                if ($metrics === $base) {
                    throw $e;
                }
                Log::warning('tiktok.adgroup.metric_fallback', ['advertiser' => $advertiserId, 'error' => $e->getMessage()]);
            }
        }

        return [];
    }

    /**
     * adgroup/get entity snapshot → adgroup_id => {name, campaign_id, status,
     * daily/lifetime budget}. Best-effort. budget_mode BUDGET_MODE_DAY → daily,
     * BUDGET_MODE_TOTAL → lifetime.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchEntities(string $advertiserId): array
    {
        try {
            $rows = $this->client->paged('adgroup/get/', [
                'advertiser_id' => $advertiserId,
                'fields'        => json_encode(['adgroup_id', 'adgroup_name', 'campaign_id', 'budget', 'budget_mode', 'operation_status', 'secondary_status']),
            ]);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['adgroup_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $budget = isset($row['budget']) && $row['budget'] !== '' ? round((float) $row['budget'], 2) : null;
            $mode   = (string) ($row['budget_mode'] ?? '');
            $status = (string) ($row['secondary_status'] ?? $row['operation_status'] ?? '');
            $out[$id] = [
                'name'            => (string) ($row['adgroup_name'] ?? ''),
                'campaign_id'     => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
                'status'          => $status !== '' ? $status : null,
                'daily_budget'    => $mode === 'BUDGET_MODE_DAY' ? $budget : null,
                'lifetime_budget' => $mode === 'BUDGET_MODE_TOTAL' ? $budget : null,
            ];
        }

        return $out;
    }

    /** @return array<int, string> */
    private function advertiserIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['advertiser_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            return array_values(array_map(static fn ($i) => (string) $i, $ids));
        }

        return $conn->external_id ? [(string) $conn->external_id] : [];
    }
}
