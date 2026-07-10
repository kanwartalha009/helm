<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdCampaignDailyMetric;
use App\Models\AdCreativeDaily;
use App\Models\AdSetDailyMetric;
use App\Models\Brand;
use App\Platforms\Meta\MetaCreativeFetcher;
use App\Platforms\TikTok\CreativeFetcher as TikTokCreativeFetcher;
use App\Services\Aggregation\AdsOverviewQuery;
use App\Services\Rules\AdSetFlags;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ads hub — per-brand ad-platform Overview (Meta today; platform-agnostic shape
 * for Google/TikTok). Brand-scoped behind the access.brand middleware +
 * BrandPolicy, so a user only sees a brand they're assigned to.
 *
 *   GET /api/brands/{brand}/ads?period=last7|last30|mtd|custom&from=&to=&currency=USD
 */
class AdsController extends Controller
{
    public function __construct(private readonly AdsOverviewQuery $query) {}

    public function show(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'period'   => ['nullable', 'in:last7,last14,last30,mtd,lastmonth,custom'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'in:native,USD,usd'],
            'platform' => ['nullable', 'in:meta,google,tiktok'],
        ]);

        return response()->json($this->query->run($brand, $params));
    }

    /**
     * Campaign drill-down (Phase B) — one campaign's KPIs + daily trend.
     *
     *   GET /api/brands/{brand}/ads/campaigns/{campaign}?period=…&currency=USD
     */
    public function campaign(Request $request, Brand $brand, string $campaign): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'period'   => ['nullable', 'in:last7,last14,last30,mtd,lastmonth,custom'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'in:native,USD,usd'],
            'platform' => ['nullable', 'in:meta,google,tiktok'],
        ]);

        // 404 a campaign that doesn't belong to this brand rather than returning
        // an all-zero shell for an id fished from the URL.
        $exists = AdCampaignDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $params['platform'] ?? 'meta')
            ->where('campaign_id', $campaign)
            ->exists();
        abort_unless($exists, 404);

        return response()->json($this->query->campaignDetail($brand, $campaign, $params));
    }

    /**
     * Ad-set drill-down (spec §4 Phase 4) — one campaign's ad sets (Google ad
     * groups / PMax asset groups, TikTok ad groups) with USD spend, ROAS, budget,
     * learning status and plain underperformer flags from the AdSetFlags engine.
     *
     *   GET /api/brands/{brand}/ads/campaigns/{campaign}/adsets?period=…&platform=meta
     */
    public function adsets(Request $request, Brand $brand, string $campaign, AdSetFlags $flags): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'period'   => ['nullable', 'in:last7,last14,last30,mtd,lastmonth,custom'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'platform' => ['nullable', 'in:meta,google,tiktok'],
        ]);
        $platform = $params['platform'] ?? 'meta';

        // 404 an id fished from the URL. Accept a campaign known to EITHER the
        // campaign or ad-set table — a brand mid-backfill may have ad-set rows
        // before the campaign row for the same id lands (or vice versa).
        $known = AdCampaignDailyMetric::query()
                ->where('brand_id', $brand->id)->where('platform', $platform)->where('campaign_id', $campaign)->exists()
            || AdSetDailyMetric::query()
                ->where('brand_id', $brand->id)->where('platform', $platform)->where('campaign_id', $campaign)->exists();
        abort_unless($known, 404);

        [$start, $end] = $this->query->resolveWindow($brand, $params);
        $res = $flags->forCampaign($brand, $platform, $campaign, CarbonImmutable::parse($start), CarbonImmutable::parse($end));

        return response()->json([
            'platform'   => $platform,
            'campaignId' => $campaign,
            'period'     => ['start' => $start, 'end' => $end],
            'asOf'       => $res['asOf'],
            'adSets'     => $res['rows'],
        ]);
    }

    /**
     * Top creatives (Phase D) — ad-level cards with thumbnails.
     *
     *   GET /api/brands/{brand}/ads/creatives?period=…&currency=USD
     */
    public function creatives(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'period'   => ['nullable', 'in:last7,last14,last30,mtd,lastmonth,custom'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'in:native,USD,usd'],
            'platform' => ['nullable', 'in:meta,google,tiktok'],
        ]);

        return response()->json($this->query->creatives($brand, $params));
    }

    /**
     * Fresh, playable source URL for one video creative (Phase D) — resolved on
     * demand because Meta's video source URLs are short-lived CDN links that go
     * stale if stored. 404s an ad that isn't this brand's; returns {url:null}
     * when the ad has no accessible video (image ad, dark post, permission-gated)
     * so the UI can fall back to the poster image without erroring.
     *
     *   GET /api/brands/{brand}/ads/creatives/{ad}/video
     */
    public function creativeVideo(Brand $brand, string $ad, MetaCreativeFetcher $meta, TikTokCreativeFetcher $tiktok): JsonResponse
    {
        $this->authorize('view', $brand);

        // Which platform owns this ad? ad_creative_daily is keyed by platform, so
        // resolve it and dispatch to the right fetcher (video source URLs are
        // short-lived on both, so always resolved on demand).
        $platform = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->where('ad_id', $ad)
            ->value('platform');
        abort_unless(in_array($platform, ['meta', 'tiktok'], true), 404);

        $conn = $brand->connections()
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();
        if (! $conn) {
            return response()->json(['url' => null]);
        }

        $url = $platform === 'meta'
            ? $meta->fetchVideoSource($conn, $ad)
            : $tiktok->fetchVideoSource($conn, $ad);

        return response()->json(['url' => $url]);
    }
}
