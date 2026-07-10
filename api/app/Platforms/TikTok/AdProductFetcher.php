<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\PlatformConnection;
use App\Platforms\Meta\AdProductFetcher as MetaAdProductFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TikTok ad spend attributed to a Shopify PRODUCT by the ad's landing URL (spec §4
 * Phase 5). An AUCTION_AD report gives spend per (ad, day); an ad/get entity call
 * gives each ad's landing_page_url, which the shared handle regex
 * (MetaAdProductFetcher::classify) maps to a product handle, else the reserved
 * __collection / __other buckets. Returns flat NATIVE rows (advertiser currency ≈
 * brand base); the backfill stamps fx + upserts with platform='tiktok'.
 *
 * Ads whose landing URL isn't a Shopify product page (app installs, TikTok Shop
 * product-anchor ads with no web URL, dynamic catalogue) fall to __other — their
 * spend is preserved, never smeared onto a product.
 */
class AdProductFetcher
{
    public function __construct(private readonly TikTokClient $client) {}

    /** @return array<int, array{date: string, key: string, spend: float, ads: int, currency: string}> */
    public function fetchRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $advertiserIds = $this->advertiserIdsFor($conn);
        if ($advertiserIds === []) {
            return [];
        }
        $currency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $agg = [];
        foreach ($advertiserIds as $advertiserId) {
            $urls = $this->landingUrls($advertiserId); // ad_id => landing_page_url

            foreach ($this->adRows($advertiserId, $from->toDateString(), $to->toDateString()) as $row) {
                $dims  = $row['dimensions'] ?? [];
                $m     = $row['metrics'] ?? [];
                $adId  = (string) ($dims['ad_id'] ?? '');
                $day   = substr((string) ($dims['stat_time_day'] ?? ''), 0, 10);
                $spend = (float) ($m['spend'] ?? 0);
                if ($adId === '' || $day === '' || $spend <= 0) {
                    continue;
                }
                $key = MetaAdProductFetcher::classify((string) ($urls[$adId] ?? ''));

                $k = $day . '|' . $key;
                if (! isset($agg[$k])) {
                    $agg[$k] = ['date' => $day, 'key' => $key, 'spend' => 0.0, 'ads' => []];
                }
                $agg[$k]['spend'] += $spend;
                $agg[$k]['ads'][$adId] = true;
            }
        }

        $out = [];
        foreach ($agg as $a) {
            $out[] = [
                'date'     => $a['date'],
                'key'      => $a['key'],
                'spend'    => round($a['spend'], 2),
                'ads'      => count($a['ads']),
                'currency' => $currency,
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> paged ad-day rows (ad_id, stat_time_day, spend). */
    private function adRows(string $advertiserId, string $from, string $to): array
    {
        return $this->client->paged('report/integrated/get/', [
            'advertiser_id' => $advertiserId,
            'report_type'   => 'BASIC',
            'data_level'    => 'AUCTION_AD',
            'dimensions'    => json_encode(['ad_id', 'stat_time_day']),
            'metrics'       => json_encode(['spend']),
            'start_date'    => $from,
            'end_date'      => $to,
        ]);
    }

    /**
     * ad/get → ad_id => landing_page_url. Best-effort: if it fails, every ad falls
     * to __other (spend preserved, just not product-mapped).
     *
     * @return array<string, string>
     */
    private function landingUrls(string $advertiserId): array
    {
        try {
            $rows = $this->client->paged('ad/get/', [
                'advertiser_id' => $advertiserId,
                'fields'        => json_encode(['ad_id', 'landing_page_url']),
            ]);
        } catch (Throwable $e) {
            Log::warning('tiktok.ad_product.entities_failed', ['advertiser' => $advertiserId, 'error' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['ad_id'] ?? '');
            if ($id !== '') {
                $out[$id] = (string) ($row['landing_page_url'] ?? '');
            }
        }

        return $out;
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
