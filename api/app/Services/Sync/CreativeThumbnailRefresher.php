<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\Brand;
use App\Platforms\Meta\MetaCreativeFetcher;
use App\Platforms\TikTok\CreativeFetcher as TikTokCreativeFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps creative thumbnails ALIVE before they expire.
 *
 * ══ THE PROBLEM ══
 * `ad_creative_daily.thumbnail_url` is a SHORT-LIVED SIGNED CDN LINK (Meta and TikTok both). The
 * daily sync writes only TODAY's rows — so an ad that ran three weeks ago keeps the URL it was
 * stored with, that URL quietly expires, and the card goes blank in the Creatives view even though
 * the ad is still inside the 30-day window and still spending.
 *
 * Refreshing on READ is not an option: the Creatives grid shows ~200 cards, and resolving assets
 * per card would mean hundreds of Meta calls per page view.
 *
 * ══ THE FIX ══
 * Once a day, for every ad with rows in the DISPLAY WINDOW (the widest the UI can show), re-resolve
 * the creative assets and write the fresh URL onto EVERY row for that ad in the window — not just
 * today's. One batched asset lookup per ~50 ads; no insights quota is spent, because this pulls
 * assets only, never metrics.
 *
 * Ads whose stored URL is still fresh (`pulled_at` newer than the staleness threshold) are skipped,
 * so a re-run costs nothing.
 */
class CreativeThumbnailRefresher
{
    public function __construct(
        private readonly MetaCreativeFetcher $meta,
        private readonly TikTokCreativeFetcher $tiktok,
    ) {}

    /**
     * @param int $windowDays how far back the Creatives view can look (the 30D tab + headroom)
     * @param int $staleHours refresh an ad whose rows were last touched longer ago than this
     * @return int the number of ROWS whose thumbnail was refreshed
     */
    public function refresh(Brand $brand, int $windowDays = 35, int $staleHours = 20): int
    {
        $from     = CarbonImmutable::now($brand->timezone ?: 'UTC')->subDays($windowDays)->toDateString();
        $staleAt  = CarbonImmutable::now()->subHours(max(1, $staleHours));
        $refreshed = 0;

        foreach (['meta', 'tiktok'] as $platform) {
            $conn = $brand->connections->firstWhere('platform', $platform);
            if (! $conn || $conn->status !== 'active') {
                continue;
            }
            $conn->setRelation('brand', $brand);

            // Ads visible in the window whose stored asset is stale OR missing entirely.
            // MAX(pulled_at) per ad: if ANY row for the ad was refreshed recently, the URL we'd
            // fetch is the same one, so there is nothing to do.
            $adIds = DB::table('ad_creative_daily')
                ->where('brand_id', $brand->id)
                ->where('platform', $platform)
                ->where('date', '>=', $from)
                ->groupBy('ad_id')
                ->havingRaw('MAX(pulled_at) < ? OR MAX(thumbnail_url) IS NULL', [$staleAt])
                ->pluck('ad_id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if ($adIds === []) {
                continue;
            }

            try {
                $assets = $platform === 'meta'
                    ? $this->meta->refreshAssets($conn, $adIds)
                    : $this->tiktok->refreshAssets($conn, $adIds);
            } catch (Throwable $e) {
                Log::warning('sync.creative_thumbnails.failed', [
                    'brand_id' => $brand->id,
                    'platform' => $platform,
                    'ads'      => count($adIds),
                    'error'    => $e->getMessage(),
                ]);
                continue;   // best-effort: a stale thumbnail must never break a sync
            }

            foreach ($assets as $adId => $asset) {
                $url = $asset['image'] ?? null;
                if ($url === null || $url === '') {
                    // Meta gave us no image for this ad. Do NOT null out what we have — a stored
                    // URL that might still work beats a card that definitely shows "No preview".
                    continue;
                }

                // Write the fresh URL onto EVERY row for this ad in the window. The thumbnail is a
                // property of the AD, not of the day; the schema just happens to store it per row.
                $refreshed += DB::table('ad_creative_daily')
                    ->where('brand_id', $brand->id)
                    ->where('platform', $platform)
                    ->where('ad_id', (string) $adId)
                    ->where('date', '>=', $from)
                    ->update([
                        'thumbnail_url' => $url,
                        'media_type'    => in_array($asset['media'] ?? 'image', ['image', 'video'], true)
                            ? $asset['media'] : 'image',
                        'pulled_at'     => now(),
                    ]);
            }
        }

        return $refreshed;
    }
}
