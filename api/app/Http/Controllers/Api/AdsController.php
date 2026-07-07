<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdCampaignDailyMetric;
use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Platforms\Meta\MetaCreativeFetcher;
use App\Services\Aggregation\AdsOverviewQuery;
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
            'period'   => ['nullable', 'in:last7,last30,mtd,custom'],
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
            'period'   => ['nullable', 'in:last7,last30,mtd,custom'],
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
     * Top creatives (Phase D) — ad-level cards with thumbnails.
     *
     *   GET /api/brands/{brand}/ads/creatives?period=…&currency=USD
     */
    public function creatives(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'period'   => ['nullable', 'in:last7,last30,mtd,custom'],
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
    public function creativeVideo(Brand $brand, string $ad, MetaCreativeFetcher $fetcher): JsonResponse
    {
        $this->authorize('view', $brand);

        $belongs = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'meta')
            ->where('ad_id', $ad)
            ->exists();
        abort_unless($belongs, 404);

        $conn = $brand->connections()
            ->where('platform', 'meta')
            ->where('status', 'active')
            ->first();

        $url = $conn ? $fetcher->fetchVideoSource($conn, $ad) : null;

        return response()->json(['url' => $url]);
    }
}
