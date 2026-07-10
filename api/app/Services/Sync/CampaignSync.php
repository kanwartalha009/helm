<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\AdCampaignDailyMetric;
use App\Models\MetaBreakdownDaily;
use App\Models\PlatformConnection;
use App\Platforms\Google\ReportsFetcher;
use App\Platforms\Meta\InsightsFetcher;
use App\Platforms\TikTok\ReportsFetcher as TikTokReportsFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls campaign-level Meta + Google performance for one (brand × ad platform ×
 * day) and upserts it into ad_campaign_daily_metrics — the grain the ads audit
 * reads. Called by SyncBrandDayJob right after the account-level daily_metrics
 * row lands, so the audit stays current without a manual backfill.
 *
 * Best-effort by design: a campaign-insights failure is logged and swallowed so
 * it can NEVER fail the day's main metric sync (which has already succeeded).
 * The one-off ads:backfill-campaigns command fills history; this keeps it fresh.
 */
// Not final: SyncBrandDayJob type-hints this concrete class, and the job's
// lifecycle tests need a test double at that seam (tests/Feature/SyncBrandDayJobTest).
class CampaignSync
{
    public function __construct(
        private readonly InsightsFetcher $meta,
        private readonly ReportsFetcher $google,
        private readonly TikTokReportsFetcher $tiktok,
        private readonly FxService $fx,
    ) {}

    /** Sync one day's campaigns for an ad-platform connection. Returns rows written. */
    public function syncDay(PlatformConnection $conn, CarbonImmutable $date): int
    {
        $platform = $conn->platform;
        if (! in_array($platform, ['meta', 'google', 'tiktok'], true)) {
            return 0;
        }

        try {
            $rows = match ($platform) {
                'meta'   => $this->meta->fetchCampaignRange($conn, $date, $date),
                'google' => $this->google->fetchCampaignRange($conn, $date, $date),
                default  => $this->tiktok->fetchCampaignRange($conn, $date, $date),
            };
        } catch (Throwable $e) {
            Log::warning('sync.campaigns.failed', [
                'brand_id' => $conn->brand_id,
                'platform' => $platform,
                'date'     => $date->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        if ($rows === []) {
            return 0;
        }

        $brandId  = (int) $conn->brand_id;
        $fallback = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $records = [];
        foreach ($rows as $r) {
            $cid = (string) ($r['campaign_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $rowCcy = strtoupper((string) ($r['currency'] ?? $fallback));

            $records[] = [
                'brand_id'         => $brandId,
                'platform'         => $platform,
                'date'             => $date->toDateString(),
                'campaign_id'      => mb_substr($cid, 0, 64),
                'campaign_name'    => mb_substr((string) ($r['campaign_name'] ?? ''), 0, 255),
                // Google enrichment (status / channel / all-conv / impression
                // share) — Meta and TikTok fetchers don't emit these keys, so
                // their rows keep null (missing, not zero).
                'status'           => isset($r['status']) ? mb_substr((string) $r['status'], 0, 16) : null,
                'channel_type'     => isset($r['channel_type']) ? mb_substr((string) $r['channel_type'], 0, 32) : null,
                'spend'            => (float) ($r['spend'] ?? 0),
                'impressions'      => (int) ($r['impressions'] ?? 0),
                'clicks'           => (int) ($r['clicks'] ?? 0),
                'conversions'      => (int) ($r['conversions'] ?? 0),
                'conversion_value' => (float) ($r['conversion_value'] ?? 0),
                'all_conversions'          => isset($r['all_conversions']) ? (float) $r['all_conversions'] : null,
                'view_through_conversions' => isset($r['view_through_conversions']) ? (int) $r['view_through_conversions'] : null,
                'search_impression_share'  => isset($r['search_impression_share']) ? (float) $r['search_impression_share'] : null,
                'search_budget_lost_is'    => isset($r['search_budget_lost_is']) ? (float) $r['search_budget_lost_is'] : null,
                'currency'         => $rowCcy,
                'fx_rate_to_usd'   => $this->fx->cachedToUsd($rowCcy, $date),
                'is_complete'      => true,
                'pulled_at'        => now(),
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            AdCampaignDailyMetric::upsert(
                $chunk,
                ['brand_id', 'platform', 'date', 'campaign_id'],
                ['campaign_name', 'status', 'channel_type', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'all_conversions', 'view_through_conversions', 'search_impression_share', 'search_budget_lost_is', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
            );
        }

        return count($records);
    }

    /**
     * Sync one day's Meta spend for a breakdown axis (default: audience — the ASC
     * new/engaged/existing/unknown segments via user_segment_key) into
     * meta_breakdown_daily, powering the dashboard's Audience view. Meta-only and
     * best-effort: a failure is logged and swallowed so it never fails the day's
     * main sync. The one-off meta:backfill-breakdown fills history; this keeps it
     * fresh. Only `audience` is synced daily — other axes are backfill-on-demand.
     */
    public function syncMetaBreakdown(PlatformConnection $conn, CarbonImmutable $date, string $type = 'audience'): int
    {
        if ($conn->platform !== 'meta') {
            return 0;
        }
        $breakdowns = (array) config("meta_breakdowns.{$type}", []);
        if ($breakdowns === []) {
            return 0;
        }

        try {
            $rows = $this->meta->fetchBreakdownRange($conn, $breakdowns, $date, $date);
        } catch (Throwable $e) {
            Log::warning('sync.meta_breakdown.failed', [
                'brand_id' => $conn->brand_id,
                'type'     => $type,
                'date'     => $date->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        return $this->storeBreakdown($conn, 'meta', $date, $type, $rows);
    }

    /**
     * TikTok audience breakdown for one axis (config tiktok_breakdowns.{type}) →
     * meta_breakdown_daily[platform=tiktok]. Best-effort, mirrors syncMetaBreakdown;
     * the fetcher's metric fallback means a bad name never fails the day.
     */
    public function syncTikTokBreakdown(PlatformConnection $conn, CarbonImmutable $date, string $type): int
    {
        if ($conn->platform !== 'tiktok') {
            return 0;
        }
        $dimensions = (array) config("tiktok_breakdowns.{$type}", []);
        if ($dimensions === []) {
            return 0;
        }

        try {
            $rows = $this->tiktok->fetchBreakdownRange($conn, $dimensions, $date, $date);
        } catch (Throwable $e) {
            Log::warning('sync.tiktok_breakdown.failed', [
                'brand_id' => $conn->brand_id,
                'type'     => $type,
                'date'     => $date->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        return $this->storeBreakdown($conn, 'tiktok', $date, $type, $rows);
    }

    /**
     * Google device/country breakdown for one day → meta_breakdown_daily[platform=
     * google]. Best-effort, mirrors the Meta/TikTok breakdown syncs. `country` is
     * geographic buyer location (LOCATION_OF_PRESENCE); an unknown type is a no-op
     * rather than an error.
     */
    public function syncGoogleBreakdown(PlatformConnection $conn, CarbonImmutable $date, string $type): int
    {
        if ($conn->platform !== 'google' || ! in_array($type, ['device', 'country'], true)) {
            return 0;
        }

        try {
            $rows = $this->google->fetchBreakdownRange($conn, $type, $date, $date);
        } catch (Throwable $e) {
            Log::warning('sync.google_breakdown.failed', [
                'brand_id' => $conn->brand_id,
                'type'     => $type,
                'date'     => $date->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return 0;
        }

        return $this->storeBreakdown($conn, 'google', $date, $type, $rows);
    }

    /**
     * Shared upsert of one day's breakdown rows into meta_breakdown_daily, keyed
     * by (brand, platform, date, breakdown_type, segment). Native money + stored
     * fx snapshot (spec rule 7).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function storeBreakdown(PlatformConnection $conn, string $platform, CarbonImmutable $date, string $type, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $brandId  = (int) $conn->brand_id;
        $fallback = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $records = [];
        foreach ($rows as $r) {
            $seg = trim((string) ($r['segment_key'] ?? ''));
            if ($seg === '') {
                $seg = 'unknown';
            }
            $rowCcy = strtoupper((string) ($r['currency'] ?? $fallback));

            $records[] = [
                'brand_id'         => $brandId,
                'platform'         => $platform,
                'date'             => $date->toDateString(),
                'breakdown_type'   => $type,
                'segment_key'      => mb_substr($seg, 0, 191),
                'segment_label'    => mb_substr((string) ($r['segment_label'] ?? $seg), 0, 191),
                'spend'            => (float) ($r['spend'] ?? 0),
                'impressions'      => (int) ($r['impressions'] ?? 0),
                'clicks'           => (int) ($r['clicks'] ?? 0),
                'reach'            => (int) ($r['reach'] ?? 0),
                'conversions'      => (int) ($r['conversions'] ?? 0),
                'conversion_value' => (float) ($r['conversion_value'] ?? 0),
                'currency'         => $rowCcy,
                'fx_rate_to_usd'   => $this->fx->cachedToUsd($rowCcy, $date),
                'is_complete'      => true,
                'pulled_at'        => now(),
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            MetaBreakdownDaily::upsert(
                $chunk,
                ['brand_id', 'platform', 'date', 'breakdown_type', 'segment_key'],
                ['segment_label', 'spend', 'impressions', 'clicks', 'reach', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
            );
        }

        return count($records);
    }
}
