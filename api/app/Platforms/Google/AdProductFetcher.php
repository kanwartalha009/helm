<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Models\PlatformConnection;
use App\Platforms\Meta\AdProductFetcher as MetaAdProductFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google ad spend attributed to a Shopify PRODUCT by the ad's final URL (spec §4
 * Phase 5). GAQL `FROM ad_group_ad` yields one row per (ad, day) with its
 * final_urls — the same handle regex the Meta path uses (reused verbatim via
 * MetaAdProductFetcher::classify) maps each to a product handle, else the reserved
 * __collection / __other buckets. Returns flat NATIVE rows (cost_micros ÷ 1e6);
 * the backfill command stamps fx + upserts with platform='google'.
 *
 * Deliberately excludes Shopping + Performance Max: those bid on a product FEED
 * (no per-product final URL), so their spend is NOT URL-mappable and must stay
 * UNMAPPED rather than be smeared across products. `ad_group_ad` naturally omits
 * PMax (asset groups) and Shopping product ads, so this query only ever sees the
 * Search/Display spend that CAN be mapped.
 */
class AdProductFetcher
{
    public function __construct(private readonly GoogleAdsClient $client) {}

    /** @return array<int, array{date: string, key: string, spend: float, ads: int, currency: string}> */
    public function fetchRange(PlatformConnection $conn, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $customerIds = $this->customerIdsFor($conn);
        if ($customerIds === []) {
            return [];
        }
        $baseCurrency = strtoupper((string) ($conn->brand?->base_currency ?: 'USD'));

        $gaql = 'SELECT ad_group_ad.ad.id, ad_group_ad.ad.final_urls, customer.currency_code, '
            . 'segments.date, metrics.cost_micros '
            . "FROM ad_group_ad WHERE segments.date BETWEEN '{$from->toDateString()}' AND '{$to->toDateString()}' "
            . 'AND metrics.cost_micros > 0';

        // agg["date|key|ccy"] => running spend + distinct spending-ad set.
        $agg = [];
        foreach ($customerIds as $customerId) {
            try {
                foreach ($this->client->search($customerId, $gaql) as $row) {
                    $ada = $row->getAdGroupAd();
                    if ($ada === null) {
                        continue;
                    }
                    $ad       = $ada->getAd();
                    $day      = (string) ($row->getSegments()?->getDate() ?? '');
                    $currency = strtoupper((string) ($row->getCustomer()?->getCurrencyCode() ?: $baseCurrency));
                    $spend    = ((int) ($row->getMetrics()?->getCostMicros() ?? 0)) / 1_000_000;
                    if ($day === '' || $spend <= 0) {
                        continue;
                    }

                    $url = '';
                    foreach ($ad?->getFinalUrls() ?? [] as $u) {
                        if ((string) $u !== '') {
                            $url = (string) $u;
                            break;
                        }
                    }
                    $key  = MetaAdProductFetcher::classify($url);
                    $adId = (string) ($ad?->getId() ?? '');

                    $k = $day . '|' . $key . '|' . $currency;
                    if (! isset($agg[$k])) {
                        $agg[$k] = ['date' => $day, 'key' => $key, 'currency' => $currency, 'spend' => 0.0, 'ads' => []];
                    }
                    $agg[$k]['spend'] += $spend;
                    if ($adId !== '') {
                        $agg[$k]['ads'][$adId] = true;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('google.ad_product.failed', ['customer' => $customerId, 'error' => $e->getMessage()]);
            }
        }

        $out = [];
        foreach ($agg as $a) {
            $out[] = [
                'date'     => $a['date'],
                'key'      => $a['key'],
                'spend'    => round($a['spend'], 2),
                'ads'      => count($a['ads']),
                'currency' => $a['currency'],
            ];
        }

        return $out;
    }

    /** @return array<int, string> */
    private function customerIdsFor(PlatformConnection $conn): array
    {
        $ids = $conn->metadata['customer_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            return array_values(array_map(static fn ($i) => (string) $i, $ids));
        }

        return $conn->external_id ? [(string) $conn->external_id] : [];
    }
}
