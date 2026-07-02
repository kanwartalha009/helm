<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Attributes Meta ad spend to a Shopify PRODUCT by the ad's landing URL — the
 * spend/ROAS/ads engine of the Inventory Intelligence report. Confirmed live via
 * meta:diagnose-ad-urls (2026-07-01): the destination URL lives in the creative's
 * video/link call-to-action or the flexible asset feed, and product URLs look
 * like /(<market>/)?products/<handle>.
 *
 * Per (brand × account) over a date range: pull daily ad-level spend
 * (time_increment=1), resolve each spending ad's landing URL → product handle
 * (market prefix stripped so /en/products/x and /products/x combine), and
 * aggregate spend + distinct-ad count per (day, product_key). Non-product spend
 * is PRESERVED, not dropped: /collections/… → __collection, everything else
 * (dynamic / Advantage+ / home) → __other, so the report reconciles to the
 * brand's real Meta total.
 *
 * Rate-limit aware (Meta error 17 is complexity-based, and these accounts are
 * heavy): creatives are read only for ads that actually spent, batched 45 per
 * ?ids= call with pacing; the caller chunks the date range by month.
 */
final class AdProductFetcher
{
    public const RESERVED_COLLECTION = '__collection';
    public const RESERVED_OTHER      = '__other';

    /** Max ids per Graph batch (?ids=) read. Meta caps this at 50; 45 leaves headroom. */
    private const BATCH = 45;

    public function __construct(private readonly MetaClient $client) {}

    /**
     * @return array<int, array{date: string, key: string, spend: float, ads: int, currency: string}>
     */
    public function fetchDailyByProduct(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accountIds = $this->accountIdsFor($conn);
        if ($accountIds === []) {
            return [];
        }
        $ccy = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        // day => key => ['spend' => float, 'ads' => array<adId,true>]
        $agg = [];

        foreach ($accountIds as $accountId) {
            // Pull spend ONE DAY AT A TIME. A month-long level=ad, time_increment=1
            // pull is thousands of ad×day rows and truncates at the first page
            // (~500), so only the first day-and-a-half of a month landed. A single
            // day's ads comfortably fit one page → always complete, and each call
            // is small, which is also gentler on the rate limit.
            $spendByDayAd = [];   // day => adId => spend
            $adIds        = [];

            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                $day = $d->toDateString();
                try {
                    $body = $this->client->get($accountId . '/insights', [
                        'level'      => 'ad',
                        'fields'     => 'ad_id,spend,account_currency',
                        'time_range' => json_encode(['since' => $day, 'until' => $day]),
                        'limit'      => 500,
                    ]);
                } catch (Throwable $e) {
                    Log::warning('meta.ad_product.insights_failed', ['account' => $accountId, 'day' => $day, 'error' => $e->getMessage()]);
                    usleep(400_000);
                    continue;
                }

                foreach (($body['data'] ?? []) as $r) {
                    $adId = (string) ($r['ad_id'] ?? '');
                    $s    = (float) ($r['spend'] ?? 0);
                    if ($adId === '' || $s <= 0) {
                        continue;
                    }
                    $spendByDayAd[$day][$adId] = ($spendByDayAd[$day][$adId] ?? 0.0) + $s;
                    $adIds[$adId]              = true;
                    if (! empty($r['account_currency'])) {
                        $ccy = strtoupper((string) $r['account_currency']);
                    }
                }
                usleep(150_000); // pace the per-day calls
            }

            if ($adIds === []) {
                continue;
            }

            $keyByAd = $this->resolveAdKeys(array_keys($adIds));

            foreach ($spendByDayAd as $day => $ads) {
                foreach ($ads as $adId => $s) {
                    $key = $keyByAd[$adId] ?? self::RESERVED_OTHER;
                    $agg[$day][$key]['spend']      = ($agg[$day][$key]['spend'] ?? 0.0) + $s;
                    $agg[$day][$key]['ads'][$adId] = true;
                }
            }
        }

        $out = [];
        foreach ($agg as $day => $keys) {
            foreach ($keys as $key => $v) {
                $out[] = [
                    'date'     => $day,
                    'key'      => $key,
                    'spend'    => round((float) $v['spend'], 2),
                    'ads'      => count($v['ads']),
                    'currency' => $ccy,
                ];
            }
        }

        return $out;
    }

    /**
     * ad_id => product key (handle | __collection | __other), reading creatives
     * only for the given ids, batched with pacing to respect the rate limit.
     *
     * @param array<int, string> $adIds
     * @return array<string, string>
     */
    private function resolveAdKeys(array $adIds): array
    {
        $out = [];
        foreach (array_chunk($adIds, self::BATCH) as $chunk) {
            try {
                $batch = $this->client->get('', [
                    'ids'    => implode(',', $chunk),
                    'fields' => 'id,creative{object_story_spec{link_data{link,call_to_action},video_data{call_to_action},template_data{link}},asset_feed_spec{link_urls},link_url,template_url}',
                ]);
            } catch (Throwable $e) {
                Log::warning('meta.ad_product.creatives_failed', ['error' => $e->getMessage(), 'count' => count($chunk)]);
                foreach ($chunk as $id) {
                    $out[$id] = self::RESERVED_OTHER;
                }
                usleep(400_000);
                continue;
            }

            foreach ($chunk as $id) {
                $ad  = is_array($batch[$id] ?? null) ? $batch[$id] : [];
                $url = self::extractUrl((array) ($ad['creative'] ?? []));
                $out[$id] = self::classify($url);
            }
            usleep(250_000); // pace batches — Meta error 17 is complexity/volume based
        }

        return $out;
    }

    /** Product handle, else __collection, else __other. */
    public static function classify(string $url): string
    {
        if ($url === '') {
            return self::RESERVED_OTHER;
        }
        $handle = self::productHandle($url);
        if ($handle !== null) {
            return $handle;
        }
        if (stripos($url, '/collections/') !== false) {
            return self::RESERVED_COLLECTION;
        }

        return self::RESERVED_OTHER;
    }

    /**
     * Best-effort landing URL from a creative, across ad types (confirmed field
     * paths from the live probe). Returns '' when none is present.
     *
     * @param array<string, mixed> $c
     */
    public static function extractUrl(array $c): string
    {
        $oss  = (array) ($c['object_story_spec'] ?? []);
        $feed = (array) ($c['asset_feed_spec'] ?? []);

        $candidates = [
            $oss['video_data']['call_to_action']['value']['link'] ?? null, // most common (video ads)
            $oss['link_data']['link'] ?? null,
            $oss['link_data']['call_to_action']['value']['link'] ?? null,
            $oss['template_data']['link'] ?? null,
            $feed['link_urls'][0]['website_url'] ?? null,
            $c['link_url'] ?? null,
            $c['template_url'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return '';
    }

    /** Shopify product handle from a landing URL, ignoring the market prefix (/it, /fr-fr, …). */
    public static function productHandle(string $url): ?string
    {
        if (preg_match('~(?:/[a-z]{2}(?:-[a-z]{2})?)?/products/([^/?#]+)~i', $url, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * The ad accounts to pull for this brand: the selected list when present,
     * else the single external_id. Mirrors InsightsFetcher.
     *
     * @return array<int, string>
     */
    private function accountIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['ad_account_ids'] ?? null;
        $list = is_array($ids) && $ids !== []
            ? array_values(array_map(static fn ($i) => (string) $i, $ids))
            : ($conn->external_id ? [(string) $conn->external_id] : []);

        return array_map(static fn ($id) => str_starts_with($id, 'act_') ? $id : 'act_' . $id, $list);
    }
}
