<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ad-level (creative) performance for the Ads hub Creatives view (Phase D):
 * per (day, ad) spend / impressions / clicks / attributed purchases + value,
 * plus the ad name and a creative thumbnail. Mirrors AdProductFetcher's ad-level
 * pull (level=ad, one day per call because a month-long ad×day pull truncates at
 * the first page) and its batched ?ids= creative read.
 *
 * Purchases use the locked 7d_click attribution, same as InsightsFetcher, so a
 * creative's ROAS reconciles with the account/campaign views.
 *
 * Rate-limit aware: thumbnails are read only for ads that appeared, batched 45
 * per ?ids= call with pacing; the caller chunks the date range.
 */
final class MetaCreativeFetcher
{
    /** Max ids per Graph batch (?ids=) read. Meta caps at 50; 45 leaves headroom. */
    private const BATCH = 45;

    private const ATTRIBUTION_WINDOW = '7d_click';

    /** Purchase action types in priority order — first present wins (mirrors InsightsFetcher). */
    private const PURCHASE_ACTION_TYPES = [
        'omni_purchase',
        'purchase',
        'offsite_conversion.fb_pixel_purchase',
    ];

    /** 3-second video views (Meta's `video_view` action) → Thumbstop numerator. */
    private const VIDEO_3S_ACTION_TYPES = ['video_view'];

    /** Add-to-cart in priority order — first present wins → Click-to-ATC numerator. */
    private const ADD_TO_CART_ACTION_TYPES = [
        'omni_add_to_cart',
        'add_to_cart',
        'offsite_conversion.fb_pixel_add_to_cart',
    ];

    public function __construct(private readonly MetaClient $client) {}

    /**
     * Per (day, ad) rows over [from, to], native currency. thumbnail_url is filled
     * from a single batched creative read after the metrics are collected.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCreativeRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accountIds = $this->accountIdsFor($conn);
        if ($accountIds === []) {
            return [];
        }
        $fallbackCcy = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $rows  = [];
        $adIds = []; // ad_id => true, for the thumbnail lookup

        foreach ($accountIds as $accountId) {
            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                $day = $d->toDateString();
                try {
                    $body = $this->client->get($accountId . '/insights', [
                        'level'                      => 'ad',
                        'fields'                     => 'ad_id,ad_name,campaign_id,spend,impressions,clicks,actions,action_values,video_thruplay_watched_actions,account_currency',
                        'action_attribution_windows' => json_encode([self::ATTRIBUTION_WINDOW]),
                        'time_range'                 => json_encode(['since' => $day, 'until' => $day]),
                        'limit'                      => 500,
                    ]);
                } catch (Throwable $e) {
                    Log::warning('meta.creative.insights_failed', ['account' => $accountId, 'day' => $day, 'error' => $e->getMessage()]);
                    usleep(400_000);
                    continue;
                }

                foreach (($body['data'] ?? []) as $r) {
                    $adId = (string) ($r['ad_id'] ?? '');
                    if ($adId === '') {
                        continue;
                    }
                    $adIds[$adId] = true;

                    $rows[] = [
                        'date'             => $day,
                        'ad_id'            => $adId,
                        'ad_name'          => (string) ($r['ad_name'] ?? ''),
                        'campaign_id'      => (string) ($r['campaign_id'] ?? ''),
                        'spend'            => isset($r['spend']) ? round((float) $r['spend'], 2) : 0.0,
                        'impressions'      => (int) ($r['impressions'] ?? 0),
                        'clicks'           => (int) ($r['clicks'] ?? 0),
                        'conversions'      => (int) round(self::attributedTotal($r['actions'] ?? [], self::PURCHASE_ACTION_TYPES)),
                        'conversion_value' => round(self::attributedTotal($r['action_values'] ?? [], self::PURCHASE_ACTION_TYPES), 2),
                        'video_3s'         => (int) round(self::attributedTotal($r['actions'] ?? [], self::VIDEO_3S_ACTION_TYPES)),
                        'thruplays'        => (int) round(self::sumFieldValues($r['video_thruplay_watched_actions'] ?? [])),
                        'add_to_cart'      => (int) round(self::attributedTotal($r['actions'] ?? [], self::ADD_TO_CART_ACTION_TYPES)),
                        'currency'         => strtoupper((string) ($r['account_currency'] ?? $fallbackCcy)),
                        'thumbnail_url'    => null,
                        'media_type'       => 'image',
                    ];
                }
                usleep(150_000); // pace the per-day calls
            }
        }

        if ($adIds === []) {
            return [];
        }

        $creatives = $this->resolveCreatives(array_keys($adIds));
        foreach ($rows as &$row) {
            $c = $creatives[$row['ad_id']] ?? null;
            $row['thumbnail_url'] = $c['image'] ?? null;
            $row['media_type']    = $c['media'] ?? 'image';
        }
        unset($row);

        return $rows;
    }

    /** Server-side render size for Meta's thumbnail_url — big enough for a retina card. */
    private const THUMB_PX = 600;

    /**
     * ad_id => ['image' => best display image URL, 'media' => 'image'|'video'],
     * batched with pacing.
     *
     * Quality: most top-spend ads are VIDEO, which have no image_url — the naive
     * fallback is Meta's ~64px thumbnail_url, which pixelates in the card. We ask
     * Meta to RENDER thumbnail_url at THUMB_PX (top-level thumbnail_width/height —
     * Meta resizes server-side; ignored harmlessly if unsupported) and promote it
     * above the tiny link picture. So: full image_url → video poster → feed image
     * → sized thumbnail → link picture.
     *
     * @param array<int, string> $adIds
     * @return array<string, array{image: ?string, media: string}>
     */
    private function resolveCreatives(array $adIds): array
    {
        $out = [];
        foreach (array_chunk($adIds, self::BATCH) as $chunk) {
            try {
                $batch = $this->client->get('', [
                    'ids'              => implode(',', $chunk),
                    'fields'           => 'id,creative{image_url,thumbnail_url,video_id,object_story_spec{video_data{video_id,image_url},link_data{picture}},asset_feed_spec{videos{video_id,thumbnail_url},images{url}}}',
                    'thumbnail_width'  => self::THUMB_PX,
                    'thumbnail_height' => self::THUMB_PX,
                ]);
            } catch (Throwable $e) {
                Log::warning('meta.creative.creatives_failed', ['error' => $e->getMessage(), 'count' => count($chunk)]);
                foreach ($chunk as $id) {
                    $out[$id] = ['image' => null, 'media' => 'image'];
                }
                usleep(400_000);
                continue;
            }

            foreach ($chunk as $id) {
                $cr   = is_array($batch[$id] ?? null) ? (array) ($batch[$id]['creative'] ?? []) : [];
                $oss  = (array) ($cr['object_story_spec'] ?? []);
                $feed = (array) ($cr['asset_feed_spec'] ?? []);

                $videoId = $oss['video_data']['video_id']
                    ?? $cr['video_id']
                    ?? ($feed['videos'][0]['video_id'] ?? null);

                // Best display image, in priority order. thumbnail_url is now the
                // SIZED render (THUMB_PX), so it beats the tiny link picture — it's
                // what saves the video cards from pixelating.
                $image = $cr['image_url']
                    ?? ($oss['video_data']['image_url'] ?? null)
                    ?? ($feed['images'][0]['url'] ?? null)
                    ?? ($cr['thumbnail_url'] ?? null)
                    ?? ($feed['videos'][0]['thumbnail_url'] ?? null)
                    ?? ($oss['link_data']['picture'] ?? null);

                $out[$id] = [
                    'image' => is_string($image) && $image !== '' ? $image : null,
                    'media' => $videoId ? 'video' : 'image',
                ];
            }
            usleep(250_000); // pace batches — Meta error 17 is complexity/volume based
        }

        return $out;
    }

    /**
     * Resolve a FRESH, playable video source URL for one ad — on demand, because
     * Meta's source URLs are short-lived CDN links that would be stale if stored.
     * Returns null when the ad isn't a video or the source isn't accessible (dark
     * posts / permission-gated), so the UI can fall back gracefully.
     */
    public function fetchVideoSource(PlatformConnection $conn, string $adId): ?string
    {
        try {
            $ad = $this->client->get($adId, ['fields' => 'creative{video_id,object_story_spec{video_data{video_id}},asset_feed_spec{videos{video_id}}}']);
        } catch (Throwable $e) {
            Log::warning('meta.creative.video_ad_failed', ['ad' => $adId, 'error' => $e->getMessage()]);
            return null;
        }
        $cr  = (array) ($ad['creative'] ?? []);
        // video_id can sit in object_story_spec (single video), directly on the
        // creative, or under asset_feed_spec (dynamic creative) — check all three.
        $vid = $cr['object_story_spec']['video_data']['video_id']
            ?? $cr['video_id']
            ?? ($cr['asset_feed_spec']['videos'][0]['video_id'] ?? null);
        if (! $vid) {
            return null;
        }
        try {
            $v = $this->client->get((string) $vid, ['fields' => 'source']);
        } catch (Throwable $e) {
            Log::warning('meta.creative.video_source_failed', ['video' => $vid, 'error' => $e->getMessage()]);
            return null;
        }
        $src = $v['source'] ?? null;

        return is_string($src) && $src !== '' ? $src : null;
    }

    /**
     * Attributed value for the first purchase action type present (7d_click).
     * Copied from InsightsFetcher so the two agree on what a "purchase" is.
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
                $val = $action[self::ATTRIBUTION_WINDOW] ?? $action['value'] ?? 0;
                return is_numeric($val) ? (float) $val : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Sum the `value` of every entry in a Meta insights field returned as an
     * array of {action_type, value} — e.g. video_thruplay_watched_actions, which
     * isn't part of the `actions` breakdown and has no attribution window.
     *
     * @param array<int, array<string, mixed>> $field
     */
    private static function sumFieldValues(array $field): float
    {
        $total = 0.0;
        foreach ($field as $entry) {
            if (is_array($entry) && isset($entry['value']) && is_numeric($entry['value'])) {
                $total += (float) $entry['value'];
            }
        }

        return $total;
    }

    /**
     * The ad accounts to pull for this brand: the selected list when present,
     * else the single external_id. Mirrors InsightsFetcher / AdProductFetcher.
     *
     * @return array<int, string>
     */
    private function accountIdsFor(PlatformConnection $conn): array
    {
        $ids  = $conn->metadata['ad_account_ids'] ?? null;
        $list = is_array($ids) && $ids !== []
            ? array_values(array_map(static fn ($i) => (string) $i, $ids))
            : ($conn->external_id ? [(string) $conn->external_id] : []);

        return array_map(static fn ($id) => str_starts_with($id, 'act_') ? $id : 'act_' . $id, $list);
    }
}
