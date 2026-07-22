<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\PlatformConnection;
use App\Support\LandingPathMapper;
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
// Not final: CampaignSync type-hints this concrete class for the daily
// ad-product sync, and its tests need a test double at that seam
// (tests/Feature/InventoryQueryTest) — same reasoning as CampaignSync itself.
class AdProductFetcher
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
        // day => campaign-level spend (the reconciling truth). recon:ads-spend
        // confirmed campaign spend == account spend within 0.5%. Used to top up
        // __other so the product table captures the ~35% of Meta spend that has
        // NO ad-level row to attribute (Advantage+ / partnership / dark posts).
        $campaignByDay = [];

        foreach ($accountIds as $accountId) {
            // Campaign-level truth FIRST — before the ad-level continue below —
            // because an account whose spend is entirely partnership/dark returns
            // no ad-level rows at all, yet its campaign spend is real and must
            // still land in the product table's __other, never be dropped.
            foreach ($this->campaignSpendByDay($accountId, $from, $to) as $day => $sp) {
                $campaignByDay[$day] = ($campaignByDay[$day] ?? 0.0) + $sp;
            }

            // Pull spend ONE DAY AT A TIME, FOLLOWING PAGINATION.
            //
            // ══ WHY paged(), NOT get() WITH limit:500 ══
            // The old comment here claimed "a single day's ads comfortably fit one page → always
            // complete". Measured false on Flabelus (act_987893265344995): EVERY day in an 8-day
            // window returned a FULL 500-row page — the account runs 700+ ads/day. The single-page
            // read saw only the top 500 by Meta's default ordering and dropped the rest, and the
            // rest is the LOW-SPEND TAIL. So a product advertised with a few cents (real case: "pip",
            // 3 active ads, ~€4 total) was invisible: 0 ads, "—" spend in Inventory, its budget
            // silently swept into the __other bucket. It affected EVERY low-spend product on EVERY
            // large account, and inflated the unattributed total on the page.
            //
            // `paged()` follows Graph's `paging.next` cursor to the end, routing every page through
            // MetaClient::request() — which already backs off on rate-limit (error 17). Day-by-day
            // is still the unit (a month-ranged level=ad pull truncates far worse and can't be
            // reliably paged), but each day is now read in FULL.
            $spendByDayAd = [];   // day => adId => spend
            $adIds        = [];

            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                $day = $d->toDateString();
                try {
                    $rows = $this->client->paged($accountId . '/insights', [
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

                foreach ($rows as $r) {
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

        // Reconcile the product table to the campaign truth: any per-day spend the
        // ad-level pull couldn't see (partnership/dark = no ad rows; Advantage+/
        // dynamic = partial) is added to __other, so Σ(product rows) == account
        // spend. The money is shown honestly as "not product-specific", never
        // dropped and never faked onto a product (Kanwar, 2026-07-22 incident).
        return self::reconcileToCampaign($out, $campaignByDay, $ccy);
    }

    /**
     * Campaign-level spend per day for one account over the range (one call,
     * time_increment=1). This is the reconciling truth — recon:ads-spend measured
     * campaign spend == account spend to the cent, while the level=ad pull runs
     * ~35% short on accounts heavy with Advantage+ / partnership / dark-post ads.
     * Degrades to [] on failure (no top-up rather than a wrong one).
     *
     * @return array<string, float> date (Y-m-d) => spend
     */
    private function campaignSpendByDay(string $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $rows = $this->client->paged($accountId . '/insights', [
                'level'          => 'campaign',
                'fields'         => 'spend',
                'time_range'     => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
                'time_increment' => 1,
                'limit'          => 500,
            ]);
        } catch (Throwable $e) {
            Log::warning('meta.ad_product.campaign_insights_failed', ['account' => $accountId, 'error' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            // With time_increment=1 each row carries its day in date_start.
            $day = substr((string) ($r['date_start'] ?? ''), 0, 10);
            $sp  = (float) ($r['spend'] ?? 0);
            if ($day !== '' && $sp > 0) {
                $out[$day] = ($out[$day] ?? 0.0) + $sp;
            }
        }

        return $out;
    }

    /**
     * Top up the __other bucket per day so the product rows reconcile to the
     * campaign-level truth. For each day whose campaign spend exceeds the summed
     * attributed spend, the positive remainder is added to that day's __other row
     * (created if absent). A day where the ad level already ≥ campaign is left
     * alone (never subtract). Pure — unit-tested without any Meta call.
     *
     * @param array<int, array{date:string, key:string, spend:float, ads:int, currency:string}> $rows
     * @param array<string, float> $campaignByDay
     * @return array<int, array{date:string, key:string, spend:float, ads:int, currency:string}>
     */
    public static function reconcileToCampaign(array $rows, array $campaignByDay, string $ccy): array
    {
        $sumByDay      = [];
        $otherIdxByDay = [];
        foreach ($rows as $i => $r) {
            $day = (string) $r['date'];
            $sumByDay[$day] = ($sumByDay[$day] ?? 0.0) + (float) $r['spend'];
            if ($r['key'] === self::RESERVED_OTHER) {
                $otherIdxByDay[$day] = $i;
            }
        }

        foreach ($campaignByDay as $day => $campaignSpend) {
            $day  = (string) $day;
            $diff = round((float) $campaignSpend - ($sumByDay[$day] ?? 0.0), 2);
            if ($diff <= 0.01) {
                continue; // ad-level already reconciles (or exceeds) — nothing to add
            }
            if (isset($otherIdxByDay[$day])) {
                $rows[$otherIdxByDay[$day]]['spend'] = round((float) $rows[$otherIdxByDay[$day]]['spend'] + $diff, 2);
            } else {
                $rows[] = ['date' => $day, 'key' => self::RESERVED_OTHER, 'spend' => $diff, 'ads' => 0, 'currency' => $ccy];
            }
        }

        return $rows;
    }

    /**
     * ad_id => product key (handle | __collection | __other), reading creatives
     * only for the given ids, batched with pacing to respect the rate limit.
     *
     * NOTE (Kanwar, 2026-07-22): an attempt to add `effective_object_story_spec`
     * here for partnership/whitelisted ads was WITHDRAWN — that field does not
     * exist on the adcreative node (Meta error 100), and requesting it 400s the
     * whole batch, dropping every ad to __other. The measured Bruna gap is not a
     * URL-resolution problem anyway: recon:ads-spend showed level=ad spend running
     * ~35% short of level=campaign, i.e. ~€24k/wk of ads simply aren't in the
     * ad-level pull to attribute (see meta:diagnose-ad-vs-campaign-spend). This
     * field set is the confirmed-working one from meta:diagnose-ad-urls.
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

    /**
     * Shopify product handle from a landing URL, ignoring the market prefix (/it, /fr-fr, …).
     *
     * Delegates to LandingPathMapper, which now owns the ONE canonical regex. Shopify session
     * landing paths resolve through the same code, so a Meta ad's landing URL and a Shopify
     * session on the same product can never disagree about the handle — which would split one
     * product's numbers across two rows.
     */
    public static function productHandle(string $url): ?string
    {
        return LandingPathMapper::productHandle($url);
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
