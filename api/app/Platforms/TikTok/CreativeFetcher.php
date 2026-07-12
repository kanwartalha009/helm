<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ad-level (creative) TikTok performance for the Ads hub Creatives tab →
 * ad_creative_daily[platform=tiktok]. Mirrors Meta's MetaCreativeFetcher: per
 * (day, ad) spend / impressions / clicks / purchases + value, plus video
 * engagement (2-sec → Thumbstop, 6-sec → Hold) and add-to-cart, plus a creative
 * thumbnail + media type resolved from TikTok's /ad/get + /file endpoints.
 *
 * Metric names are config-driven (services.tiktok.*), rich→base fallback so a bad
 * name never fails the pull. Thumbnail resolution is BEST-EFFORT: if TikTok's
 * asset endpoints don't return (untested shapes), the row still lands with the
 * metrics + no preview — the grid degrades exactly like Meta's "No preview".
 */
final class CreativeFetcher
{
    private const BATCH = 50;

    public function __construct(private readonly TikTokClient $client) {}

    /** @return array<int, array<string, mixed>> */
    public function fetchCreativeRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $advertiserIds = $this->advertiserIdsFor($conn);
        if ($advertiserIds === []) {
            return [];
        }
        $currency       = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));
        $purchaseMetric = (string) config('services.tiktok.purchase_metric', 'complete_payment');
        $valueMetric    = (string) config('services.tiktok.value_metric', 'value_per_complete_payment');
        $cartMetric     = (string) config('services.tiktok.cart_metric', 'total_add_to_cart');
        $perPurchase    = config('services.tiktok.value_metric_kind', 'per_purchase') === 'per_purchase';

        $rows = [];
        foreach ($advertiserIds as $advertiserId) {
            $adIds  = [];
            $offset = count($rows);

            foreach ($this->adRows($advertiserId, $from->toDateString(), $to->toDateString(), $purchaseMetric, $valueMetric, $cartMetric) as $row) {
                $dims = $row['dimensions'] ?? [];
                $m    = $row['metrics'] ?? [];
                $adId = (string) ($dims['ad_id'] ?? '');
                $day  = substr((string) ($dims['stat_time_day'] ?? ''), 0, 10);
                if ($adId === '' || $day === '') {
                    continue;
                }
                $adIds[$adId] = true;

                $purch = (int) round((float) ($m[$purchaseMetric] ?? $m['conversion'] ?? 0));
                $val   = (float) ($m[$valueMetric] ?? 0);

                $rows[] = [
                    'date'             => $day,
                    'ad_id'            => $adId,
                    'ad_name'          => (string) ($m['ad_name'] ?? ''),
                    'campaign_id'      => null,
                    'spend'            => isset($m['spend']) ? round((float) $m['spend'], 2) : 0.0,
                    'impressions'      => (int) ($m['impressions'] ?? 0),
                    'clicks'           => (int) ($m['clicks'] ?? 0),
                    'video_3s'         => (int) round((float) ($m['video_watched_2s'] ?? 0)), // TS numerator
                    'thruplays'        => (int) round((float) ($m['video_watched_6s'] ?? 0)), // HR numerator
                    'add_to_cart'      => (int) round((float) ($m[$cartMetric] ?? 0)),
                    'conversions'      => $purch,
                    'conversion_value' => $perPurchase ? $val * $purch : $val,
                    'currency'         => $currency,
                    'thumbnail_url'    => null,
                    'media_type'       => 'image',
                    'body_text'        => null, // filled from ad/get ad_text below (Ads Library winners search)
                ];
            }

            if ($adIds === []) {
                continue;
            }

            $creatives = $this->resolveCreatives($advertiserId, array_keys($adIds));
            for ($i = $offset, $n = count($rows); $i < $n; $i++) {
                $c = $creatives[$rows[$i]['ad_id']] ?? null;
                if ($c !== null) {
                    $rows[$i]['thumbnail_url'] = $c['image'];
                    $rows[$i]['media_type']   = $c['media'];
                    $rows[$i]['body_text']    = $c['body'] ?? null;
                } elseif ((int) $rows[$i]['video_3s'] > 0) {
                    // Asset resolution failed but the ad has video views → it's a
                    // video (so TS/HR still render), just without a thumbnail.
                    $rows[$i]['media_type'] = 'video';
                }
            }
        }

        return $rows;
    }

    /**
     * Paged ad-day rows, rich→base metric fallback.
     *
     * @return array<int, array<string, mixed>>
     */
    private function adRows(string $advertiserId, string $from, string $to, string $purchaseMetric, string $valueMetric, string $cartMetric): array
    {
        $base = ['ad_name', 'spend', 'impressions', 'clicks', 'conversion', 'video_watched_2s', 'video_watched_6s'];
        // Revenue tier (purchase + value). cart_metric, when set, is the one
        // UNVALIDATED name — keep it in its OWN outer tier so a bad cart name only
        // drops CtATC, never revenue. When cart_metric is '' (this account has no
        // ATC event) we skip that tier so we don't burn a doomed call every sync.
        $mid   = array_values(array_unique([...$base, $purchaseMetric, $valueMetric]));
        $tiers = $cartMetric !== ''
            ? [array_values(array_unique([...$mid, $cartMetric])), $mid, $base]
            : [$mid, $base];

        foreach ($tiers as $metrics) {
            try {
                return $this->client->paged('report/integrated/get/', [
                    'advertiser_id' => $advertiserId,
                    'report_type'   => 'BASIC',
                    'data_level'    => 'AUCTION_AD',
                    'dimensions'    => json_encode(['ad_id', 'stat_time_day']),
                    'metrics'       => json_encode($metrics),
                    'start_date'    => $from,
                    'end_date'      => $to,
                ]);
            } catch (RuntimeException $e) {
                if ($metrics === $base) {
                    throw $e;
                }
                Log::warning('tiktok.creative.metric_fallback', ['advertiser' => $advertiserId, 'error' => $e->getMessage()]);
            }
        }

        return [];
    }

    /**
     * ad_id => ['image' => thumbnail url|null, 'media' => 'image'|'video'].
     * Best-effort via /ad/get then /file/video|image/ad/info; any failure returns
     * a partial/empty map so the grid degrades to no-preview, never errors.
     *
     * @param array<int, string> $adIds
     * @return array<string, array{image: ?string, media: string}>
     */
    private function resolveCreatives(string $advertiserId, array $adIds): array
    {
        $out = [];
        foreach (array_chunk($adIds, self::BATCH) as $chunk) {
            try {
                $data = $this->client->get('ad/get/', [
                    'advertiser_id' => $advertiserId,
                    'ad_ids'        => json_encode(array_values($chunk)),
                    'fields'        => json_encode(['ad_id', 'video_id', 'image_ids', 'ad_text']),
                ]);
            } catch (RuntimeException $e) {
                Log::warning('tiktok.creative.ad_get_failed', ['error' => $e->getMessage()]);
                continue;
            }

            $videoByAd = [];
            $imageByAd = [];
            $videoIds  = [];
            $imageIds  = [];
            $textByAd  = [];
            foreach (($data['list'] ?? []) as $ad) {
                $adId = (string) ($ad['ad_id'] ?? '');
                if ($adId === '') {
                    continue;
                }
                $textByAd[$adId] = trim((string) ($ad['ad_text'] ?? ''));
                $vid  = (string) ($ad['video_id'] ?? '');
                $imgs = is_array($ad['image_ids'] ?? null) ? $ad['image_ids'] : [];
                if ($vid !== '') {
                    $videoByAd[$adId] = $vid;
                    $videoIds[$vid]   = true;
                } elseif ($imgs !== []) {
                    $imageByAd[$adId]              = (string) $imgs[0];
                    $imageIds[(string) $imgs[0]]  = true;
                }
            }

            $posters = $this->resolveVideoPosters($advertiserId, array_keys($videoIds));
            $images  = $this->resolveImageUrls($advertiserId, array_keys($imageIds));

            foreach ($videoByAd as $adId => $vid) {
                $out[$adId] = ['image' => $posters[$vid] ?? null, 'media' => 'video'];
            }
            foreach ($imageByAd as $adId => $imgId) {
                $out[$adId] = ['image' => $images[$imgId] ?? null, 'media' => 'image'];
            }
            // Attach ad_text to whatever media entry exists; a text-only ad still
            // gets a body row so winners search finds it.
            foreach ($textByAd as $adId => $text) {
                if ($text === '') {
                    continue;
                }
                if (isset($out[$adId])) {
                    $out[$adId]['body'] = $text;
                } else {
                    $out[$adId] = ['image' => null, 'media' => 'image', 'body' => $text];
                }
            }
            usleep(250_000);
        }

        return $out;
    }

    /**
     * @param array<int, string> $videoIds
     * @return array<string, ?string> video_id => poster url
     */
    private function resolveVideoPosters(string $advertiserId, array $videoIds): array
    {
        $out = [];
        foreach (array_chunk($videoIds, self::BATCH) as $chunk) {
            try {
                $data = $this->client->get('file/video/ad/info/', [
                    'advertiser_id' => $advertiserId,
                    'video_ids'     => json_encode(array_values($chunk)),
                ]);
            } catch (RuntimeException $e) {
                Log::warning('tiktok.creative.video_info_failed', ['error' => $e->getMessage()]);
                continue;
            }
            foreach (($data['list'] ?? []) as $v) {
                $vid = (string) ($v['video_id'] ?? '');
                if ($vid === '') {
                    continue;
                }
                $poster    = (string) ($v['poster_url'] ?? $v['video_cover_url'] ?? '');
                $out[$vid] = $poster !== '' ? $poster : null;
            }
            usleep(200_000);
        }

        return $out;
    }

    /**
     * @param array<int, string> $imageIds
     * @return array<string, ?string> image_id => url
     */
    private function resolveImageUrls(string $advertiserId, array $imageIds): array
    {
        $out = [];
        foreach (array_chunk($imageIds, self::BATCH) as $chunk) {
            try {
                $data = $this->client->get('file/image/ad/info/', [
                    'advertiser_id' => $advertiserId,
                    'image_ids'     => json_encode(array_values($chunk)),
                ]);
            } catch (RuntimeException $e) {
                Log::warning('tiktok.creative.image_info_failed', ['error' => $e->getMessage()]);
                continue;
            }
            foreach (($data['list'] ?? []) as $img) {
                $iid = (string) ($img['image_id'] ?? '');
                if ($iid === '') {
                    continue;
                }
                $url       = (string) ($img['image_url'] ?? '');
                $out[$iid] = $url !== '' ? $url : null;
            }
            usleep(200_000);
        }

        return $out;
    }

    /**
     * Fresh playable video source for one ad — resolve ad → video_id →
     * preview_url. Null when the ad isn't a video or the source isn't accessible.
     */
    public function fetchVideoSource(PlatformConnection $conn, string $adId): ?string
    {
        foreach ($this->advertiserIdsFor($conn) as $advertiserId) {
            try {
                $ad = $this->client->get('ad/get/', [
                    'advertiser_id' => $advertiserId,
                    'ad_ids'        => json_encode([$adId]),
                    'fields'        => json_encode(['ad_id', 'video_id']),
                ]);
            } catch (RuntimeException $e) {
                Log::warning('tiktok.creative.video_ad_failed', ['ad' => $adId, 'error' => $e->getMessage()]);
                continue;
            }

            $vid = '';
            foreach (($ad['list'] ?? []) as $a) {
                if ((string) ($a['ad_id'] ?? '') === $adId) {
                    $vid = (string) ($a['video_id'] ?? '');
                    break;
                }
            }
            if ($vid === '') {
                continue;
            }

            try {
                $v = $this->client->get('file/video/ad/info/', [
                    'advertiser_id' => $advertiserId,
                    'video_ids'     => json_encode([$vid]),
                ]);
            } catch (RuntimeException $e) {
                Log::warning('tiktok.creative.video_source_failed', ['video' => $vid, 'error' => $e->getMessage()]);
                continue;
            }
            foreach (($v['list'] ?? []) as $info) {
                $url = (string) ($info['preview_url'] ?? '');
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
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
