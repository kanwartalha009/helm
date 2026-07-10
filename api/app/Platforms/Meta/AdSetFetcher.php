<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Meta ad-SET level insights + entity snapshot (spec §4 Phase 3b). Mirrors
 * InsightsFetcher::fetchCampaignRange at level=adset, adds reach/frequency, and a
 * per-account /adsets entity call for budget + learning status merged onto each
 * day's rows. Returns flat NATIVE-currency rows; the sync/backfill stamps fx.
 *
 * Attribution matches InsightsFetcher (7-day click, purchase-action priority).
 * The tiny helpers are duplicated here rather than widening InsightsFetcher's
 * private surface while that adapter is under parallel edit.
 */
final class AdSetFetcher
{
    /** Purchase action types, priority order — mirrors InsightsFetcher. */
    private const PURCHASE_ACTION_TYPES = [
        'omni_purchase',
        'purchase',
        'offsite_conversion.fb_pixel_purchase',
    ];

    public function __construct(private readonly MetaClient $client) {}

    /**
     * @return array<int, array<string, mixed>> one row per (day, ad_set_id)
     */
    public function fetchRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accountIds = $this->accountIdsFor($conn);
        if ($accountIds === []) {
            return [];
        }
        $fallbackCcy = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $out = [];
        foreach ($accountIds as $accountId) {
            $acct   = InsightsFetcher::normalizeAccountId($accountId);
            $entity = $this->fetchEntities($acct); // ad_set_id => budget/status/learning snapshot

            $rows = $this->client->paged($acct . '/insights', [
                'level'                           => 'adset',
                'fields'                          => 'adset_id,adset_name,campaign_id,spend,impressions,clicks,reach,frequency,actions,action_values,account_currency',
                'action_attribution_windows'      => json_encode([InsightsFetcher::ATTRIBUTION_WINDOW]),
                'time_range'                      => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
                'time_increment'                  => 1,
                'use_account_attribution_setting' => 'false',
                'limit'                           => 500,
            ]);

            foreach ($rows as $row) {
                $day = (string) ($row['date_start'] ?? '');
                $sid = (string) ($row['adset_id'] ?? '');
                if ($day === '' || $sid === '') {
                    continue;
                }
                $e = $entity[$sid] ?? [];
                $out[] = [
                    'date'             => $day,
                    'ad_set_id'        => $sid,
                    'ad_set_name'      => (string) ($row['adset_name'] ?? ''),
                    'campaign_id'      => (string) ($row['campaign_id'] ?? ''),
                    'entity_kind'      => 'ad_set',
                    'spend'            => isset($row['spend']) ? round((float) $row['spend'], 2) : 0.0,
                    'impressions'      => (int) ($row['impressions'] ?? 0),
                    'clicks'           => (int) ($row['clicks'] ?? 0),
                    'reach'            => isset($row['reach']) ? (int) $row['reach'] : null,
                    'frequency'        => isset($row['frequency']) ? round((float) $row['frequency'], 4) : null,
                    'conversions'      => (int) round(self::attributedTotal($row['actions'] ?? [], self::PURCHASE_ACTION_TYPES)),
                    'conversion_value' => round(self::attributedTotal($row['action_values'] ?? [], self::PURCHASE_ACTION_TYPES), 2),
                    'currency'         => strtoupper((string) ($row['account_currency'] ?? $fallbackCcy)),
                    'status'           => $e['status'] ?? null,
                    'learning_status'  => $e['learning_status'] ?? null,
                    'daily_budget'     => $e['daily_budget'] ?? null,
                    'lifetime_budget'  => $e['lifetime_budget'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * One entity call per account — budget (minor units → ÷100) + effective status
     * + learning stage, keyed by ad set id. Best-effort: if it fails the metric
     * rows still land, just without budget/status (point-in-time snapshot, no history).
     *
     * @return array<string, array{status: ?string, daily_budget: ?float, lifetime_budget: ?float, learning_status: ?string}>
     */
    private function fetchEntities(string $normalizedAccountId): array
    {
        try {
            $rows = $this->client->paged($normalizedAccountId . '/adsets', [
                'fields' => 'id,name,effective_status,daily_budget,lifetime_budget,learning_stage_info{status}',
                'limit'  => 500,
            ]);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            // Meta budgets come back in MINOR units (cents) — VERIFY on one real account.
            $daily    = isset($row['daily_budget']) && $row['daily_budget'] !== '' ? round((float) $row['daily_budget'] / 100, 2) : null;
            $lifetime = isset($row['lifetime_budget']) && $row['lifetime_budget'] !== '' ? round((float) $row['lifetime_budget'] / 100, 2) : null;
            $learning = $row['learning_stage_info']['status'] ?? null;
            $out[$id] = [
                'status'          => isset($row['effective_status']) ? (string) $row['effective_status'] : null,
                'daily_budget'    => $daily,
                'lifetime_budget' => $lifetime,
                'learning_status' => $learning !== null ? (string) $learning : null,
            ];
        }

        return $out;
    }

    /** @return array<int, string> */
    private function accountIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['ad_account_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            return array_values(array_map(static fn ($i) => (string) $i, $ids));
        }

        return $conn->external_id ? [(string) $conn->external_id] : [];
    }

    /**
     * First present purchase-action type's attributed value.
     *
     * @param array<int, array<string, mixed>> $actions
     * @param array<int, string> $types
     */
    private static function attributedTotal(array $actions, array $types): float
    {
        foreach ($types as $type) {
            foreach ($actions as $action) {
                if (! is_array($action) || ($action['action_type'] ?? null) !== $type) {
                    continue;
                }
                $val = $action[InsightsFetcher::ATTRIBUTION_WINDOW] ?? $action['value'] ?? 0;

                return is_numeric($val) ? (float) $val : 0.0;
            }
        }

        return 0.0;
    }
}
