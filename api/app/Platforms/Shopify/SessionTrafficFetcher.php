<?php

declare(strict_types=1);

namespace App\Platforms\Shopify;

use App\Models\PlatformConnection;
use App\Support\LandingPathMapper;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sessions by traffic type, per landing entity, per day (Bosco item B — probe verdict B1).
 *
 * ShopifyQL:
 *     FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type
 *     ORDER BY sessions DESC LIMIT <n> OFFSET <n> SINCE <day> UNTIL <day>
 *
 * ══ THE TWO THINGS THAT MAKE THIS HONEST ══
 *
 * 1. IT PAGES, IT DOES NOT CAP. The spec proposed "top-N landing paths per day, log a
 *    truncation note". Measured on a real store: capping product rows at 300 captured only
 *    74.3% of product-page sessions, and every one of the missing 4,393 sat in the TAIL —
 *    i.e. the low-traffic products an inventory tool exists to surface. A cap doesn't lose
 *    data at random; it loses exactly the data Bosco is looking for. The probe proved OFFSET
 *    works, so this pages until a short page comes back and takes the whole day.
 *
 * 2. IT RECONCILES, AND FAILS CLOSED IF IT CAN'T. Every day is checked against a second,
 *    cheap ShopifyQL call (`GROUP BY traffic_type`, 4 rows) that returns the store's own
 *    total. If the paged sum doesn't match, the day is written `is_complete = false` and every
 *    read surface renders "—". A short row set that LOOKS complete is the failure mode that
 *    matters here: it would quietly under-report a product's sessions, and nobody would know.
 *    Missing is missing; it is never zero, and never a number we can't stand behind.
 *
 * Rows come back with the landing path already resolved to a product / collection / 'other'
 * (LandingPathMapper) and summed, because raw landing paths have unbounded cardinality — see
 * the migration.
 */
class SessionTrafficFetcher
{
    /** The API version that exposes `shopifyqlQuery` — same as RevenueFetcher. */
    private const SHOPIFYQL_API_VERSION = '2026-04';

    /** Rows per page. ShopifyQL honours LIMIT + OFFSET (probe-verified 2026-07-12). */
    private const PAGE_SIZE = 1000;

    /**
     * ══ THE CEILING THAT WAS SILENTLY EATING BIG DAYS ══
     * This was 25 (= 25,000 rows/day), sized from a brand that produced "under 5,000". On a real
     * high-traffic day that is nowhere near enough, and the shortfall it caused looked like a
     * mysterious reconciliation bug:
     *
     *     2026-06-28   Shopify: 152,621 sessions   our breakdown: 151,485   short 1,136
     *     2026-07-01   Shopify: 124,167 sessions   our breakdown: 122,652   short 1,515
     *     2026-07-02   Shopify: 118,841 sessions   our breakdown: 118,790   short 51
     *
     * Every checkout mints a UNIQUE one-session landing URL, so distinct-landing-path cardinality
     * scales with traffic without limit. A 150k-session day blows straight past 25,000 rows, we
     * stopped at the ceiling, and the missing rows were the one-session tail — hence a shortfall
     * of roughly "however many rows we didn't get to".
     *
     * The fix is NOT to raise the ceiling and download 40,000 junk URLs. See fetchDay(): we now
     * enumerate only products and collections, and DERIVE store-wide by subtraction. This ceiling
     * survives only to bound the legacy fallback path.
     */
    private const MAX_PAGES = 200;

    /** ShopifyQL substring predicate. Unverified against this endpoint, so its use is guarded. */
    private const PRODUCT_MATCH    = '/products/';
    private const COLLECTION_MATCH = '/collections/';

    /**
     * ══ HOW CLOSE IS "IT ADDS UP"? ══
     * The subset check compares two INDEPENDENTLY COMPUTED ShopifyQL aggregations of the same data:
     *
     *     GROUP BY traffic_type                       (Shopify's own subset total)
     *     GROUP BY landing_page_path, traffic_type    (our paged breakdown, summed)
     *
     * The first cut demanded these be BIT-EXACT. Shopify has never promised that, and in production
     * they drift by a handful of sessions on a busy day:
     *
     *     15,238 vs 15,237   (1)          27,504 vs 27,503   (1)
     *     26,969 vs 26,968   (1)          17,507 vs 17,505   (2)
     *     22,423 vs 22,422   (1)          15,507 vs 15,502   (5)
     *
     * One to five sessions in twenty-seven thousand — 0.004%. Meanwhile the REAL faults this check
     * exists to catch were 1,136 short (page ceiling), 8,861 duplicated (unstable sort) and 26,671
     * short (silent fallback): three orders of magnitude larger. The two classes are not close, and
     * a threshold between them is not a fudge — it is the difference between measurement noise and a
     * broken query.
     *
     * Demanding exactness meant blanking an entire 30-day window — every product, every number the
     * client actually looks at — to guard against a one-session rounding difference. That is not
     * rigour; it is the check doing more damage than the fault.
     *
     * So: accept a drift under 0.5% (with a 10-session floor, so a small subset isn't failed by a
     * single session). Anything larger still fails the day closed, loudly, with its reason.
     *
     * The invariant that actually matters is untouched: store-wide is DERIVED by subtraction, so the
     * parts still sum to Shopify's own store total EXACTLY. A drifted session is not lost — it lands
     * in store-wide, which is where an unattributable session belongs anyway.
     */
    private const SUBSET_TOLERANCE_RATIO = 0.005;   // 0.5%
    private const SUBSET_TOLERANCE_FLOOR = 10;      // sessions

    /**
     * Shopify's traffic types, verified against a full YEAR of a real store (2026-07-12):
     * paid 3,117,263 · direct 2,599,142 · unknown 757,967 · organic 457,105 · unattributed 7.
     * They sum EXACTLY to the store total (6,931,484).
     *
     * `unattributed` is 0.0001% of traffic and does not appear in a 30-day sample at all —
     * which is precisely why it must be in a named constant rather than inferred from a probe.
     */
    public const TRAFFIC_TYPES = ['paid', 'direct', 'organic', 'unknown', 'unattributed'];

    /**
     * One day of session/traffic-type rows for a brand, aggregated by landing entity.
     *
     * ══ WHY WE NO LONGER ENUMERATE EVERY LANDING PATH ══
     * The original design paged the full `GROUP BY landing_page_path, traffic_type` result and
     * required Σ(all rows) === the store's own total. That is unachievable on a busy store: every
     * checkout mints a UNIQUE one-session landing URL, so a 150k-session day has tens of thousands
     * of distinct paths. We hit the page ceiling, stopped, and came up short by however many rows
     * were left — which is exactly the 1,136 / 1,515 / 51 shortfalls seen in production.
     *
     * But we never WANTED those rows. Every one of them collapses into a single 'store-wide'
     * bucket. We were downloading 25,000 junk URLs in order to add them up and throw the detail
     * away — and still missing the tail.
     *
     * So: enumerate ONLY the two things we actually attribute (products and collections), which are
     * naturally bounded by the size of the catalogue, and DERIVE store-wide by subtraction:
     *
     *     store-wide[type] = storeTotal[type] − Σ(product rows)[type] − Σ(collection rows)[type]
     *
     * Nothing is lost and nothing is invented — the parts still sum to the store's own total, by
     * construction rather than by luck. It is also far CHEAPER: a handful of pages instead of 25+.
     *
     * The completeness check does not weaken. We verify, with two cheap grouped calls, that the
     * product and collection subsets were paged to the END (Σ paged product rows === Shopify's own
     * product-landing total, ditto collections). Those are the numbers we actually display; the
     * remainder is store-wide by definition. A negative remainder means the arithmetic disagrees
     * with Shopify, and fails the day.
     *
     * If ShopifyQL rejects the CONTAINS predicate (unverified against this endpoint), every bounded
     * query returns null and we fall back to the legacy full scan. Fail-safe, not fail-broken.
     *
     * @return array{rows: array<int, array<string, mixed>>, isComplete: bool, storeTotal: int|null, pagedTotal: int}
     */
    public function fetchDay(PlatformConnection $conn, string $day): array
    {
        $client = $this->makeClient($conn);

        // The truth everything is checked against: Shopify's own per-type split for the day.
        // Five rows, one cheap call. Null = we could not establish a truth → fail closed.
        $storeByType = $this->storeTotalsByType($client, $day);
        $storeTotal  = $storeByType === null ? null : array_sum($storeByType);

        if ($storeByType !== null) {
            $bounded = $this->fetchBounded($conn, $client, $day, $storeByType);
            if ($bounded !== null) {
                return $bounded;
            }

            Log::warning('shopify.session_traffic.bounded_unavailable', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'note'     => 'CONTAINS-filtered queries failed; falling back to the full landing-path scan',
            ]);
        }

        return $this->fetchLegacyFullScan($conn, $client, $day, $storeTotal);
    }

    /**
     * The legacy path: page the ENTIRE landing-path breakdown and require it to sum to the store
     * total. Retained only as a fallback for the case where ShopifyQL will not accept a CONTAINS
     * filter. It is correct but expensive, and on a very large day it can still hit the ceiling —
     * in which case it fails the day loudly rather than storing a short number.
     *
     * @return array{rows: array<int, array<string, mixed>>, isComplete: bool, storeTotal: int|null, pagedTotal: int}
     */
    private function fetchLegacyFullScan(PlatformConnection $conn, ShopifyClient $client, string $day, ?int $storeTotal): array
    {
        $raw        = [];
        $pagedTotal = 0;
        $offset     = 0;
        $truncated  = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = $this->page($client, $day, $offset);

            if ($rows === null) {
                // Transport/parse failure mid-page: we cannot know what we missed.
                return $this->incomplete($raw, $storeTotal, $pagedTotal);
            }

            foreach ($rows as $r) {
                $raw[]       = $r;
                $pagedTotal += $r['sessions'];
            }

            if (count($rows) < self::PAGE_SIZE) {
                break;             // short page = the tail. Done, nothing left behind.
            }

            $offset += self::PAGE_SIZE;

            if ($page === self::MAX_PAGES - 1) {
                $truncated = true; // we stopped early — say so, don't pretend.
            }
        }

        if ($truncated) {
            Log::warning('shopify.session_traffic.page_ceiling_hit', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'rows'     => count($raw),
            ]);

            return $this->incomplete($raw, $storeTotal, $pagedTotal);
        }

        // Reconciliation. Sessions are integers straight from Shopify, so this is an exact
        // equality — no tolerance window, because any gap means rows are missing.
        $isComplete = $storeTotal !== null && $storeTotal === $pagedTotal;

        if (! $isComplete) {
            // The DELTA and its SIGN are the diagnosis, so log them rather than making a human
            // subtract two numbers out of a log line:
            //   negative → we are SHORT rows (something is being dropped, or a page was skipped)
            //   positive → we DOUBLE-COUNTED (OFFSET paging returned a row on two pages — which
            //              can happen when `ORDER BY sessions DESC` ties across a page boundary)
            // Those are different bugs with different fixes; conflating them wastes a day.
            Log::warning('shopify.session_traffic.reconcile_failed', [
                'brand_id'    => $conn->brand_id,
                'date'        => $day,
                'store_total' => $storeTotal,
                'paged_total' => $pagedTotal,
                'delta'       => $storeTotal === null ? null : $pagedTotal - $storeTotal,
            ]);
        }

        // A traffic type we don't know about would be counted in `pagedTotal` (so it still
        // reconciles) but would have nowhere to go in the read model — it would be lost
        // SILENTLY, with the day looking green. `unattributed` was exactly that bug: 7 sessions
        // a year, invisible in a 30-day probe. So a sixth type fails the day loudly instead.
        $unknownTypes = [];
        foreach ($raw as $r) {
            $t = strtolower(trim((string) $r['traffic_type']));
            if ($t !== '' && ! in_array($t, self::TRAFFIC_TYPES, true)) {
                $unknownTypes[$t] = true;
            }
        }
        if ($unknownTypes !== []) {
            Log::warning('shopify.session_traffic.unknown_traffic_type', [
                'brand_id' => $conn->brand_id,
                'date'     => $day,
                'types'    => array_keys($unknownTypes),
            ]);
            $isComplete = false;
        }

        return [
            'rows'       => $this->aggregate($raw, $isComplete),
            'isComplete' => $isComplete,
            'storeTotal' => $storeTotal,
            'pagedTotal' => $pagedTotal,
            'reasons'    => $isComplete ? [] : [
                'Fell back to the full landing-path scan, which cannot enumerate a day this large '
                . '(every checkout mints a unique one-session URL). This happens when the filtered '
                . 'queries could not be run — usually Shopify throttling.',
            ],
        ];
    }

    /**
     * Enumerate ONLY products and collections; derive store-wide by subtraction.
     *
     * Returns null when the CONTAINS predicate isn't usable, so the caller can fall back.
     *
     * @param  array<string, int>  $storeByType  Shopify's own per-type totals for the day
     * @return array{rows: array<int, array<string, mixed>>, isComplete: bool, storeTotal: int|null, pagedTotal: int}|null
     */
    private function fetchBounded(PlatformConnection $conn, ShopifyClient $client, string $day, array $storeByType): ?array
    {
        // The two subsets we actually attribute. Bounded by the catalogue, not by traffic.
        $productRows = $this->pageFiltered($client, $day, self::PRODUCT_MATCH);
        if ($productRows === null) {
            return null;
        }

        $collectionRows = $this->pageFiltered($client, $day, self::COLLECTION_MATCH);
        if ($collectionRows === null) {
            return null;
        }

        // ══ THE COMPLETENESS CHECK ══
        // Two cheap grouped calls (5 rows each) give Shopify's OWN total for each subset. If our
        // paged rows sum to exactly that, we reached the end of each subset — which is the only
        // thing that could have gone wrong now that we no longer chase the unbounded tail.
        $checkProducts = $this->groupedTotalsByType($client, $day, self::PRODUCT_MATCH);
        if ($checkProducts === null) {
            return null;
        }

        $checkCollections = $this->groupedTotalsByType($client, $day, self::COLLECTION_MATCH);
        if ($checkCollections === null) {
            return null;
        }

        $pagedProducts    = $this->sumByType($productRows);
        $pagedCollections = $this->sumByType($collectionRows);

        $isComplete = true;
        $reasons    = [];   // WHY the day failed, in words the operator can act on

        // ══ A SIXTH TRAFFIC TYPE MUST FAIL THE DAY, LOUDLY ══
        // Store-wide is derived by subtracting attributed sessions from `storeByType`, and that
        // subtraction only walks TRAFFIC_TYPES. A type Shopify invented under us would therefore be
        // counted in the store total and then silently never subtracted OR emitted — its sessions
        // would simply evaporate, with the day looking green. `unattributed` was exactly this bug
        // once already (7 sessions a year, invisible in a 30-day probe). Once was enough.
        foreach (array_keys($storeByType) as $type) {
            if (! in_array($type, self::TRAFFIC_TYPES, true)) {
                Log::warning('shopify.session_traffic.unknown_traffic_type', [
                    'brand_id' => $conn->brand_id,
                    'date'     => $day,
                    'type'     => $type,
                ]);
                $isComplete = false;
                $reasons[]  = "Shopify returned a traffic type we do not know about ('{$type}').";
            }
        }

        foreach ([[$pagedProducts, $checkProducts, 'product'], [$pagedCollections, $checkCollections, 'collection']] as [$paged, $truth, $label]) {
            foreach (self::TRAFFIC_TYPES as $type) {
                $got  = $paged[$type] ?? 0;
                $want = $truth[$type] ?? 0;

                $drift     = abs($got - $want);
                $tolerance = max(self::SUBSET_TOLERANCE_FLOOR, (int) ceil($want * self::SUBSET_TOLERANCE_RATIO));

                if ($drift === 0) {
                    continue;
                }

                // Within tolerance: Shopify's own two aggregations of the same data disagreeing by
                // a rounding margin. Note it, do NOT fail a whole month of reporting over it.
                if ($drift <= $tolerance) {
                    Log::info('shopify.session_traffic.subset_drift_within_tolerance', [
                        'brand_id'  => $conn->brand_id,
                        'date'      => $day,
                        'subset'    => $label,
                        'type'      => $type,
                        'paged'     => $got,
                        'shopify'   => $want,
                        'drift'     => $drift,
                        'tolerance' => $tolerance,
                    ]);

                    continue;
                }

                // Materially short. THIS is a broken query — a page ceiling, an unstable sort, a
                // silent fallback — and it must still fail the day closed.
                Log::warning('shopify.session_traffic.subset_incomplete', [
                    'brand_id'  => $conn->brand_id,
                    'date'      => $day,
                    'subset'    => $label,
                    'type'      => $type,
                    'paged'     => $got,
                    'shopify'   => $want,
                    'drift'     => $drift,
                    'tolerance' => $tolerance,
                ]);
                $isComplete = false;
                $reasons[]  = sprintf(
                    'Shopify says %s %s sessions landed on %s pages, but paging that list returned only %s '
                    . '(%s missing — more than the %s allowed for rounding).',
                    number_format($want),
                    $type,
                    $label,
                    number_format($got),
                    number_format($drift),
                    number_format($tolerance),
                );
            }
        }

        /*
         * ══ STORE-WIDE BY SUBTRACTION ══
         * Everything that did not land on a product or a collection page: the homepage, /pages,
         * search, blogs, and the tens of thousands of one-off checkout URLs. We do not enumerate
         * them; we compute them. The parts therefore sum to Shopify's own total BY CONSTRUCTION.
         *
         * A collection-nested product URL (/collections/x/products/y) is matched by BOTH filters,
         * so it appears in both row sets. It is a PRODUCT landing (the visitor landed on a product
         * page), and `LandingPathMapper::resolve` says so. Counting it once here — and only once —
         * is what `attributedByType` guarantees: it sums the RESOLVED buckets, so a nested path is
         * counted as its product and never as its collection.
         */
        $merged     = $this->mergeDistinctPaths($productRows, $collectionRows);
        $buckets    = $this->aggregate($merged, $isComplete);
        $attributed = $this->attributedByType($buckets);

        $storeWide = [];
        foreach (self::TRAFFIC_TYPES as $type) {
            $remainder = ($storeByType[$type] ?? 0) - ($attributed[$type] ?? 0);

            if ($remainder < 0) {
                // We attributed MORE sessions to products+collections than Shopify says the store
                // had of that type. A few sessions of overshoot is the same aggregation drift as
                // above (Shopify's per-subset totals and its store total are computed separately),
                // and clamping to zero costs nothing. A LARGE overshoot means we are double-counting
                // and every product number is inflated — that must still fail the day.
                $overshoot = -$remainder;
                $tolerance = max(
                    self::SUBSET_TOLERANCE_FLOOR,
                    (int) ceil(($storeByType[$type] ?? 0) * self::SUBSET_TOLERANCE_RATIO),
                );

                if ($overshoot > $tolerance) {
                    Log::warning('shopify.session_traffic.negative_store_wide', [
                        'brand_id'    => $conn->brand_id,
                        'date'        => $day,
                        'type'        => $type,
                        'store_total' => $storeByType[$type] ?? 0,
                        'attributed'  => $attributed[$type] ?? 0,
                        'overshoot'   => $overshoot,
                    ]);
                    $isComplete = false;
                    $reasons[]  = sprintf(
                        'More %s sessions were attributed to products/collections (%s) than Shopify says the '
                        . 'store had in total (%s). Product numbers would be inflated, so the day is rejected.',
                        $type,
                        number_format($attributed[$type] ?? 0),
                        number_format($storeByType[$type] ?? 0),
                    );
                }

                $remainder = 0;   // never store a negative session count
            }

            $storeWide[$type] = $remainder;
        }

        // Re-stamp: `aggregate()` baked the completeness flag into every row before the
        // subtraction ran, so a failure discovered HERE has to be pushed back onto those rows.
        // Otherwise the day would be flagged incomplete while its rows claimed to be fine — and
        // the read layer trusts the rows.
        $rows = [];
        foreach ($buckets as $b) {
            $b['is_complete'] = $isComplete;
            $rows[] = $b;
        }

        foreach ($storeWide as $type => $sessions) {
            if ($sessions <= 0) {
                continue;   // a type with no store-wide traffic needs no row
            }

            $rows[] = [
                'entity_type'  => LandingPathMapper::TYPE_OTHER,
                'entity_key'   => LandingPathMapper::OTHER_KEY,
                'traffic_type' => $type,
                'sessions'     => $sessions,
                'is_complete'  => $isComplete,
            ];
        }

        $pagedTotal = array_sum($attributed) + array_sum($storeWide);

        if (! $isComplete) {
            Log::warning('shopify.session_traffic.reconcile_failed', [
                'brand_id'    => $conn->brand_id,
                'date'        => $day,
                'store_total' => array_sum($storeByType),
                'paged_total' => $pagedTotal,
                'mode'        => 'bounded',
            ]);
        }

        return [
            'rows'       => $rows,
            'isComplete' => $isComplete,
            'storeTotal' => array_sum($storeByType),
            'pagedTotal' => $pagedTotal,
            'reasons'    => $reasons,
        ];
    }

    /**
     * Merge the product and collection row sets, keeping each (path, traffic_type) ONCE.
     *
     * ══ WHY THIS IS NOT array_merge ══
     * `/collections/best-sellers/products/lucrecia` contains BOTH '/products/' and '/collections/',
     * so it comes back from BOTH filtered queries — the same row, twice, with the same session
     * count. `aggregate()` buckets by resolved entity and SUMS, so a plain merge would count that
     * product's sessions twice: its numbers would be inflated, and `attributedByType` would then
     * subtract too much from the store total and drive store-wide negative.
     *
     * Collection-nested product URLs are common (they are what a collection page links to), so this
     * is not an edge case — it would have corrupted the busiest products on every store.
     *
     * @param  array<int, array{path: string, traffic_type: string, sessions: int}>  $a
     * @param  array<int, array{path: string, traffic_type: string, sessions: int}>  $b
     * @return array<int, array{path: string, traffic_type: string, sessions: int}>
     */
    private function mergeDistinctPaths(array $a, array $b): array
    {
        $seen = [];

        foreach (array_merge($a, $b) as $r) {
            // Shopify returns one row per (landing_page_path, traffic_type), so the pair is the
            // natural identity. Same key from both queries = the same row, not two rows to add.
            $key = (string) $r['path'] . "\0" . strtolower(trim((string) $r['traffic_type']));
            $seen[$key] ??= $r;
        }

        return array_values($seen);
    }

    /**
     * Sessions per traffic type across a raw row set (before entity resolution). Used to check that
     * a paged subset reached its end.
     *
     * @param  array<int, array{path: string, traffic_type: string, sessions: int}>  $rows
     * @return array<string, int>
     */
    private function sumByType(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $t = strtolower(trim((string) $r['traffic_type']));
            if ($t === '') {
                continue;
            }
            $out[$t] = ($out[$t] ?? 0) + (int) $r['sessions'];
        }

        return $out;
    }

    /**
     * Sessions per traffic type that were ATTRIBUTED to a product or a collection — i.e. everything
     * except the store-wide bucket. This is what gets subtracted from the store total.
     *
     * Reads the RESOLVED buckets, not the raw rows, so a collection-nested product path counts once
     * (as its product) rather than twice.
     *
     * @param  array<int, array<string, mixed>>  $buckets
     * @return array<string, int>
     */
    private function attributedByType(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $b) {
            if ((string) $b['entity_type'] === LandingPathMapper::TYPE_OTHER) {
                continue;
            }
            $t = (string) $b['traffic_type'];
            $out[$t] = ($out[$t] ?? 0) + (int) $b['sessions'];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $raw
     * @return array{rows: array<int, array<string, mixed>>, isComplete: bool, storeTotal: int|null, pagedTotal: int}
     */
    private function incomplete(array $raw, ?int $storeTotal, int $pagedTotal, array $reasons = []): array
    {
        return [
            'rows'       => $this->aggregate($raw, false),
            'isComplete' => false,
            'storeTotal' => $storeTotal,
            'pagedTotal' => $pagedTotal,
            'reasons'    => $reasons,
        ];
    }

    /**
     * Resolve each landing path to its entity and SUM. This is where /es/products/jay,
     * /fr/products/jay and /collections/best-sellers/products/jay all become one `jay` row —
     * and where thousands of one-off checkout URLs collapse into a single 'store-wide' row per
     * traffic type. Nothing is discarded: the unmapped tail lands in 'other', so
     * Σ(rows) still equals the store total.
     *
     * @param array<int, array<string, mixed>> $raw
     * @return array<int, array<string, mixed>>
     */
    private function aggregate(array $raw, bool $isComplete): array
    {
        /** @var array<string, array<string, mixed>> $bucket */
        $bucket = [];

        foreach ($raw as $r) {
            $path = (string) $r['path'];
            $type = $this->normaliseTrafficType((string) $r['traffic_type']);
            if ($type === null) {
                continue;
            }

            $entity = LandingPathMapper::resolve($path);
            $key    = $entity['type'] . "\0" . $entity['key'] . "\0" . $type;

            if (! isset($bucket[$key])) {
                $bucket[$key] = [
                    'entity_type'  => $entity['type'],
                    'entity_key'   => $entity['key'],
                    'traffic_type' => $type,
                    'sessions'     => 0,
                    'is_complete'  => $isComplete,
                ];
            }

            $bucket[$key]['sessions'] += (int) $r['sessions'];
        }

        return array_values($bucket);
    }

    /**
     * Shopify's five traffic types: paid, direct, organic, unknown, unattributed.
     *
     * `unattributed` is REAL but vanishingly rare — 7 sessions in a full year on a store doing
     * 6.9M (0.0001%). A 30-day probe does not see it, which is exactly how it got left out of
     * the first cut of this file. Rare is not absent: dropping it would silently break the
     * invariant this whole class exists to defend (Σ rows = the store's own total), and the
     * reconciliation check would NOT have caught it, because `pagedTotal` is summed from the
     * raw rows BEFORE this filter runs. A row we cannot classify is a row we lose.
     *
     * An unrecognised value now poisons the day deliberately: it is returned as-is, fails the
     * allowlist at write time, and the day is marked incomplete — the right outcome for
     * "Shopify added a sixth type under us".
     *
     * @return string|null null ONLY for a genuinely empty value
     */
    private function normaliseTrafficType(string $type): ?string
    {
        $t = strtolower(trim($type));

        return $t === '' ? null : $t;
    }

    /**
     * The store's own per-traffic-type session split for the day. This is the truth every other
     * number in this class is checked against, and the base for the store-wide subtraction.
     *
     * Null on any failure — a reconciliation we cannot perform is a reconciliation that FAILED.
     *
     * @return array<string, int>|null
     */
    private function storeTotalsByType(ShopifyClient $client, string $day): ?array
    {
        return $this->groupedTotalsByType($client, $day, null);
    }

    /**
     * Sessions per traffic type for the day, optionally restricted to landing paths containing a
     * substring ('/products/', '/collections/').
     *
     * Five rows, one cheap call. With a filter, this is Shopify's OWN total for that subset — the
     * number our paged rows for that subset must match exactly, which is how we know we paged to
     * the end without having to enumerate the unbounded junk tail.
     *
     * @return array<string, int>|null
     */
    private function groupedTotalsByType(ShopifyClient $client, string $day, ?string $contains): ?array
    {
        // The filter value is a hard-coded class constant, never user input — but quote-strip it
        // anyway so it can't break out of the literal if that ever changes.
        $where = $contains === null
            ? ''
            : "WHERE landing_page_path CONTAINS '" . str_replace(["'", '"'], '', $contains) . "' ";

        // CLAUSE ORDER copied from RevenueFetcher::runGroupedSalesQuery, which has run against this
        // same endpoint in production for months: GROUP BY … SINCE … UNTIL … WHERE … LIMIT.
        // `WHERE` goes AFTER the date range, not before it. Inventing an order here would risk a
        // parse error — which ShopifyQL reports as an EMPTY TABLE, not an exception.
        $ql = 'FROM sessions SHOW sessions GROUP BY traffic_type '
            . "SINCE {$day} UNTIL {$day} "
            . $where
            . 'LIMIT 50';

        // ══ RETRY, BECAUSE THIS CALL FAILING THROWS AWAY A GOOD DAY ══
        // Observed in production: brand 76 / 2026-03-07 paged 49,274 sessions perfectly, then this
        // small query returned null — and the whole day was discarded because the CHEAP call failed
        // while the EXPENSIVE one succeeded. ShopifyQL is cost-throttled, and this fires alongside a
        // burst of paged calls, so it is the most likely thing in the sequence to get rate-limited.
        //
        // NOTE: a retry cannot rescue an UNSUPPORTED predicate — a parse error fails all three
        // attempts identically, returns null, and the caller falls back to the full scan. That is
        // the intended behaviour, not a wasted 4 seconds we care about.
        $table = null;
        foreach ([0, 1_000_000, 3_000_000] as $waitMicros) {
            if ($waitMicros > 0) {
                usleep($waitMicros);
            }

            $table = $this->runQuery($client, $ql);
            if ($table !== null) {
                break;
            }
        }

        if ($table === null) {
            Log::warning('shopify.session_traffic.grouped_total_unavailable', [
                'date'     => $day,
                'contains' => $contains,
            ]);

            return null;
        }

        [, $rows] = $table;

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! array_key_exists('sessions', $row) || ! array_key_exists('traffic_type', $row)) {
                // A row we cannot read is NOT a row worth zero. Fail rather than silently
                // under-count the number everything else is checked against.
                return null;
            }

            $type = strtolower(trim((string) $row['traffic_type']));
            if ($type === '') {
                continue;
            }

            $out[$type] = ($out[$type] ?? 0) + (int) round((float) $row['sessions']);
        }

        return $out;
    }

    /**
     * Page the landing-path breakdown for ONE subset of paths (products or collections).
     *
     * Bounded by the catalogue rather than by traffic: a store has thousands of products, not tens
     * of thousands of one-off checkout URLs. This is what makes the page ceiling irrelevant.
     *
     * NULL on any failure — including a rejected CONTAINS predicate, which is how the caller learns
     * to fall back to the legacy full scan.
     *
     * @return array<int, array{path: string, traffic_type: string, sessions: int}>|null
     */
    private function pageFiltered(ShopifyClient $client, string $day, string $contains): ?array
    {
        $filter = str_replace(["'", '"'], '', $contains);
        $out    = [];
        $offset = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            // ══ ORDER BY landing_page_path — NOT BY sessions ══
            // `ORDER BY sessions DESC` is NOT a total order. The tail of a real day is thousands of
            // rows all tied at 1 session, so Shopify is free to return tied rows in a different
            // order on every request — and OFFSET paging across an unstable sort both DUPLICATES
            // rows and SKIPS them. Measured on Nude Project, 2026-06-28: 8,861 sessions came back
            // on two pages, while `direct` finished 976 sessions SHORT and `paid` finished 14 OVER.
            // Both directions, same day. The breakdown could never have added up.
            //
            // `landing_page_path` is near-unique within a subset (at most one row per traffic type
            // shares it), so ordering by it gives a stable, essentially total order and OFFSET
            // paging becomes deterministic. Any residual wobble is caught: duplicates are deduped
            // below, and a skipped row makes the subset check fail and the day fail closed.
            //
            // Same proven clause order as RevenueFetcher: GROUP BY … SINCE … UNTIL … WHERE …
            // ORDER BY … LIMIT … OFFSET.
            $ql = 'FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type '
                . "SINCE {$day} UNTIL {$day} "
                . "WHERE landing_page_path CONTAINS '{$filter}' "
                . 'ORDER BY landing_page_path '
                . 'LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset;

            $rows = $this->parseRows($this->runQuery($client, $ql), $day);
            if ($rows === null) {
                return null;
            }

            foreach ($rows as $r) {
                // Belt to the ORDER BY's braces. If a row still arrives on two pages, take it ONCE.
                // Summing it would inflate that product and then over-subtract from store-wide,
                // driving the remainder negative — a wrong number that looks precise.
                $key = (string) $r['path'] . "\0" . strtolower(trim((string) $r['traffic_type']));
                $out[$key] ??= $r;
            }

            if (count($rows) < self::PAGE_SIZE) {
                return array_values($out);   // short page = the end of this subset
            }

            $offset += self::PAGE_SIZE;
        }

        // Ran out of pages even on a BOUNDED subset. That means a store with >200,000 distinct
        // product landing paths in one day, which is not a thing — so treat it as a failure rather
        // than quietly returning a truncated set that the subset check would then reject anyway.
        Log::warning('shopify.session_traffic.subset_ceiling_hit', [
            'date'     => $day,
            'contains' => $contains,
            'rows'     => count($out),
        ]);

        return null;
    }

    /**
     * One page of (landing_page_path, traffic_type, sessions). NULL on failure — the caller
     * must treat that as "the day is incomplete", never as "the page was empty".
     *
     * @return array<int, array{path: string, traffic_type: string, sessions: int}>|null
     */
    private function page(ShopifyClient $client, string $day, int $offset): ?array
    {
        $ql = 'FROM sessions SHOW sessions GROUP BY landing_page_path, traffic_type '
            . "SINCE {$day} UNTIL {$day} "
            . 'ORDER BY sessions DESC '
            . 'LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset;

        return $this->parseRows($this->runQuery($client, $ql), $day);
    }

    /**
     * Turn one ShopifyQL table into landing-path rows. Shared by the legacy full scan and the
     * bounded subset paging, so the two can never drift into parsing the same response differently.
     *
     * NULL on any unreadable response — the caller must treat that as "the day is incomplete",
     * never as "the page was empty".
     *
     * @param  array{0: array<int, mixed>, 1: array<int, mixed>}|null  $table
     * @return array<int, array{path: string, traffic_type: string, sessions: int}>|null
     */
    private function parseRows(?array $table, string $day): ?array
    {
        if ($table === null) {
            return null;
        }

        [, $rows] = $table;

        // ⚠️ ShopifyQL returns each row as an OBJECT KEYED BY COLUMN NAME, not as a positional
        // array:
        //
        //     {"landing_page_path": "/products/jay", "traffic_type": "paid", "sessions": "455"}
        //
        // The first cut of this method looked the columns up by index and read $row[2]. On a map
        // that is simply absent — so every path came back '', every row was skipped, and every
        // session count read as 0. The day then "reconciled" (0 === 0) and 88 stores were recorded
        // as having had zero traffic for 90 consecutive days, with total confidence.
        //
        // RevenueFetcher::groupedFunnelByDay — which has worked in production for months against
        // this same endpoint — reads $row['sessions'] by name. That was the pattern to copy.
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;   // unreadable response — fail the day, don't call it empty
            }

            // A row missing the columns we asked for is a BROKEN response, not an empty one.
            // Returning null fails the day loudly; skipping the row would resurrect the exact
            // silent-zero bug this comment exists to describe.
            if (! array_key_exists('landing_page_path', $row)
                || ! array_key_exists('traffic_type', $row)
                || ! array_key_exists('sessions', $row)) {
                Log::warning('shopify.session_traffic.unexpected_row_shape', [
                    'day'  => $day,
                    'keys' => array_keys($row),
                ]);

                return null;
            }

            // ══ A BLANK LANDING PATH IS STORE-WIDE TRAFFIC, NOT A ROW TO THROW AWAY ══
            // This used to `continue` — "a genuinely blank landing path, nothing to attribute".
            // That silently broke reconciliation for good: `storeTotal` comes from
            // `GROUP BY traffic_type`, which COUNTS these sessions, while `pagedTotal` is summed
            // from the rows this method returns, which DIDN'T. So any day on which Shopify logged
            // even one blank-path session was short by exactly that much, failed the exact-equality
            // check, and was stored `is_complete = false` — permanently, no matter how many times
            // it was re-pulled. Measured on Nude Project: 15 of 377 days poisoned this way, and
            // because the Inventory gate needs 30 CONSECUTIVE clean days, that ~4% failure rate was
            // enough to make the 30-day window unrenderable on essentially every brand.
            //
            // The row is not unattributable — it's UNATTRIBUTED-TO-A-PRODUCT, which is the whole
            // point of the store-wide bucket. `LandingPathMapper::resolve('')` already folds it
            // there. Keeping it preserves the one invariant this class exists to defend:
            // Σ(rows) === the store's own total.
            $path = trim((string) $row['landing_page_path']);

            $out[] = [
                'path'         => $path,   // '' → store-wide, via LandingPathMapper::resolve()
                'traffic_type' => (string) $row['traffic_type'],
                'sessions'     => (int) round((float) $row['sessions']),
            ];
        }

        return $out;
    }

    /**
     * ══ RETRY TRANSIENT FAILURES; NEVER RETRY A PARSE ERROR ══
     * ShopifyQL is cost-throttled. A single throttled page request used to return null, which made
     * `pageFiltered` return null, which made the BOUNDED path bail out, which dropped us into the
     * legacy full scan — which hits the page ceiling and comes up tens of thousands of sessions
     * short. That is exactly 2026-07-12 (105,008 vs 78,337): one transient 429 during a busy
     * backfill silently downgraded a working strategy to a broken one.
     *
     * So: transport failures are retried with a widening backoff. Parse errors are NOT — they are
     * deterministic, three attempts would fail identically, and the only thing retrying buys is a
     * four-second pause before the same answer.
     *
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}|null
     */
    private function runQuery(ShopifyClient $client, string $ql): ?array
    {
        $gql = <<<'GQL'
query ($q: String!) {
  shopifyqlQuery(query: $q) {
    tableData { columns { name } rows }
    parseErrors
  }
}
GQL;

        $lastError = null;

        foreach ([0, 1_000_000, 3_000_000, 7_000_000] as $waitMicros) {
            if ($waitMicros > 0) {
                usleep($waitMicros);
            }

            try {
                $data = $client->graphql($gql, ['q' => $ql], self::SHOPIFYQL_API_VERSION);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();   // throttle / timeout / 5xx — worth another go
                continue;
            }

            $resp = $data['shopifyqlQuery'] ?? null;
            if (! is_array($resp)) {
                $lastError = 'malformed response envelope';
                continue;
            }

            // DETERMINISTIC. Retrying cannot help, and pretending otherwise hides the real fault.
            if (! empty($resp['parseErrors'])) {
                $pe = $resp['parseErrors'];
                Log::warning('shopify.session_traffic.parse_error', [
                    'parseErrors' => is_array($pe) ? json_encode($pe) : (string) $pe,
                    'ql'          => $ql,
                ]);

                return null;
            }

            $columns = $resp['tableData']['columns'] ?? [];
            $rows    = $resp['tableData']['rows'] ?? [];

            if (! is_array($columns) || ! is_array($rows)) {
                $lastError = 'malformed tableData';
                continue;
            }

            return [$columns, $rows];
        }

        Log::warning('shopify.session_traffic.request_failed', [
            'error'    => $lastError,
            'attempts' => 4,
            'ql'       => $ql,
        ]);

        return null;
    }

    private function makeClient(PlatformConnection $conn): ShopifyClient
    {
        $accessToken = (string) ($conn->credentials['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Shopify connection is missing access_token.');
        }

        return new ShopifyClient((string) $conn->external_id, $accessToken);
    }
}
