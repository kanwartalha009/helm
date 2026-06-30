<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\AdCampaignDailyMetric;
use App\Models\MetaBreakdownDaily;
use App\Models\PlatformConnection;
use App\Platforms\Google\ReportsFetcher;
use App\Platforms\Meta\InsightsFetcher;
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
final class CampaignSync
{
    public function __construct(
        private readonly InsightsFetcher $meta,
        private readonly ReportsFetcher $google,
        private readonly FxService $fx,
    ) {}

    /** Sync one day's campaigns for an ad-platform connection. Returns rows written. */
    public function syncDay(PlatformConnection $conn, CarbonImmutable $date): int
    {
        $platform = $conn->platform;
        if (! in_array($platform, ['meta', 'google'], true)) {
            return 0;
        }

        try {
            $rows = $platform === 'meta'
                ? $this->meta->fetchCampaignRange($conn, $date, $date)
                : $this->google->fetchCampaignRange($conn, $date, $date);
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
                'spend'            => (float) ($r['spend'] ?? 0),
                'impressions'      => (int) ($r['impressions'] ?? 0),
                'clicks'           => (int) ($r['clicks'] ?? 0),
                'conversions'      => (int) ($r['conversions'] ?? 0),
                'conversion_value' => (float) ($r['conversion_value'] ?? 0),
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
                ['campaign_name', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
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
                'date'             => $date->toDateString(),
                'breakdown_type'   => $type,
                'segment_key'      => mb_substr($seg, 0, 191),
                'segment_label'    => mb_substr((string) ($r['segment_label'] ?? $seg), 0, 191),
                'spend'            => (float) ($r['spend'] ?? 0),
                'impressions'      => (int) ($r['impressions'] ?? 0),
                'clicks'           => (int) ($r['clicks'] ?? 0),
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
                ['brand_id', 'date', 'breakdown_type', 'segment_key'],
                ['segment_label', 'spend', 'impressions', 'clicks', 'conversions', 'conversion_value', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
            );
        }

        return count($records);
    }
}
