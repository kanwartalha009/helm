<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\AdSetDailyMetric;
use App\Models\PlatformConnection;
use App\Platforms\Google\AdGroupFetcher as GoogleAdGroupFetcher;
use App\Platforms\Meta\AdSetFetcher as MetaAdSetFetcher;
use App\Platforms\TikTok\AdGroupFetcher as TikTokAdGroupFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upserts ad-set / ad-group / asset-group daily rows into ad_set_daily_metrics
 * (spec §4 Phase 3c). One entry point used by BOTH the daily sync (from=to=date)
 * and the ranged backfill — same fx-snapshot pipeline as CampaignSync. fx is
 * stamped per ROW date (a ranged pull spans days), cached per (currency, date).
 * Best-effort: a platform hiccup logs and returns 0, never failing the caller.
 */
final class AdSetSync
{
    public function __construct(
        private readonly MetaAdSetFetcher $meta,
        private readonly GoogleAdGroupFetcher $google,
        private readonly TikTokAdGroupFetcher $tiktok,
        private readonly FxService $fx,
    ) {}

    public function syncRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $platform = $conn->platform;
        if (! in_array($platform, ['meta', 'google', 'tiktok'], true)) {
            return 0;
        }

        try {
            $rows = match ($platform) {
                'meta'   => $this->meta->fetchRange($conn, $from, $to),
                'google' => $this->google->fetchRange($conn, $from, $to),
                default  => $this->tiktok->fetchRange($conn, $from, $to),
            };
        } catch (Throwable $e) {
            Log::warning('sync.adsets.failed', [
                'brand_id' => $conn->brand_id,
                'platform' => $platform,
                'from'     => $from->toDateString(),
                'to'       => $to->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        if ($rows === []) {
            return 0;
        }

        $brandId  = (int) $conn->brand_id;
        $fallback = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $fxCache  = [];

        $records = [];
        foreach ($rows as $r) {
            $sid = (string) ($r['ad_set_id'] ?? '');
            $day = (string) ($r['date'] ?? '');
            if ($sid === '' || $day === '') {
                continue;
            }
            $rowCcy = strtoupper((string) ($r['currency'] ?? $fallback));
            $fxKey  = $rowCcy . '|' . $day;
            $fx     = $fxCache[$fxKey] ??= $this->fx->cachedToUsd($rowCcy, CarbonImmutable::parse($day));

            $records[] = [
                'brand_id'                => $brandId,
                'platform'                => $platform,
                'date'                    => $day,
                'ad_set_id'               => mb_substr($sid, 0, 64),
                'ad_set_name'             => isset($r['ad_set_name']) ? mb_substr((string) $r['ad_set_name'], 0, 255) : null,
                'campaign_id'             => isset($r['campaign_id']) && $r['campaign_id'] !== null ? mb_substr((string) $r['campaign_id'], 0, 64) : null,
                'entity_kind'             => mb_substr((string) ($r['entity_kind'] ?? 'ad_set'), 0, 16),
                'status'                  => isset($r['status']) && $r['status'] !== null ? mb_substr((string) $r['status'], 0, 32) : null,
                'learning_status'         => isset($r['learning_status']) && $r['learning_status'] !== null ? mb_substr((string) $r['learning_status'], 0, 16) : null,
                'daily_budget'            => $r['daily_budget'] ?? null,
                'lifetime_budget'         => $r['lifetime_budget'] ?? null,
                'spend'                   => (float) ($r['spend'] ?? 0),
                'impressions'             => (int) ($r['impressions'] ?? 0),
                'clicks'                  => (int) ($r['clicks'] ?? 0),
                'reach'                   => isset($r['reach']) && $r['reach'] !== null ? (int) $r['reach'] : null,
                'frequency'               => isset($r['frequency']) && $r['frequency'] !== null ? (float) $r['frequency'] : null,
                'conversions'             => (int) ($r['conversions'] ?? 0),
                'conversion_value'        => (float) ($r['conversion_value'] ?? 0),
                'search_impression_share' => isset($r['search_impression_share']) && $r['search_impression_share'] !== null ? (float) $r['search_impression_share'] : null,
                'search_budget_lost_is'   => isset($r['search_budget_lost_is']) && $r['search_budget_lost_is'] !== null ? (float) $r['search_budget_lost_is'] : null,
                'currency'                => $rowCcy,
                'fx_rate_to_usd'          => $fx,
                'is_complete'             => true,
                'pulled_at'               => now(),
            ];
        }

        if ($records === []) {
            return 0;
        }

        foreach (array_chunk($records, 500) as $chunk) {
            AdSetDailyMetric::upsert(
                $chunk,
                ['brand_id', 'platform', 'date', 'ad_set_id'],
                ['ad_set_name', 'campaign_id', 'entity_kind', 'status', 'learning_status', 'daily_budget', 'lifetime_budget', 'spend', 'impressions', 'clicks', 'reach', 'frequency', 'conversions', 'conversion_value', 'search_impression_share', 'search_budget_lost_is', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
            );
        }

        return count($records);
    }
}
