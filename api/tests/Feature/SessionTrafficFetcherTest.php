<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\SessionTrafficDaily;
use App\Models\SessionTrafficDay;
use App\Platforms\Shopify\SessionTrafficFetcher;
use App\Services\Sync\SessionTrafficSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bosco item B — the fetcher: pagination and the reconciliation gate.
 *
 * This is where a silent-truncation bug would actually live. The spec proposed capping at the
 * top-N landing paths per day; measured on a real store that cap would have dropped ~26% of
 * product-page sessions, all of it from the tail — i.e. precisely the low-traffic products
 * Inventory Intelligence exists to surface. So the fetcher pages instead, and then PROVES it
 * got everything by adding the rows up and comparing against Shopify's own store total.
 *
 * If it can't prove that, it says so (is_complete = false) rather than shipping a short number
 * with a confident face.
 */
final class SessionTrafficFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-09';

    /**
     * Created through the MODEL, not DB::table — `credentials` is an `encrypted:array` cast,
     * so a raw JSON insert would not decrypt, and this fetcher genuinely reads the token.
     */
    private function conn(): PlatformConnection
    {
        $brand = Brand::factory()->create(['timezone' => 'UTC', 'status' => 'active']);

        return PlatformConnection::create([
            'brand_id'    => $brand->id,
            'platform'    => 'shopify',
            'external_id' => 'test-shop.myshopify.com',
            'status'      => 'active',
            'credentials' => ['access_token' => 'shpat_test'],
        ]);
    }

    /**
     * A ShopifyQL tableData payload.
     *
     * ⚠️ `rows` are OBJECTS KEYED BY COLUMN NAME — that is what Shopify actually returns:
     *
     *     {"landing_page_path": "/products/jay", "traffic_type": "paid", "sessions": "455"}
     *
     * This helper originally emitted POSITIONAL arrays (["/products/jay", "paid", "455"]), which
     * matched the bug in the fetcher rather than reality. Every test passed while production
     * returned zeros for 88 brands across 90 days. A fixture that agrees with the code instead of
     * with the API tests nothing at all — so the shape is built from the column names here, and
     * callers still pass rows positionally for readability.
     *
     * @param array<int, string>              $columns
     * @param array<int, array<int, string>>  $rows positional; zipped onto $columns
     */
    private function table(array $columns, array $rows): array
    {
        return [
            'data' => [
                'shopifyqlQuery' => [
                    'tableData'  => [
                        'columns' => array_map(static fn (string $c): array => ['name' => $c], $columns),
                        // Zip each positional row onto the column names — Shopify's real shape.
                        'rows'    => array_map(
                            static fn (array $r): array => array_combine($columns, $r),
                            $rows,
                        ),
                    ],
                    'parseErrors' => [],
                ],
            ],
        ];
    }

    /**
     * Index resolved rows by "entity:traffic_type" so an assertion names what it means.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    private function byKey(array $rows)
    {
        return collect($rows)->keyBy(fn (array $r): string => $r['entity_key'] . ':' . $r['traffic_type']);
    }

    /** The store-level 4-row split the fetcher reconciles against. */
    private function totalsResponse(int $paid, int $direct, int $organic, int $unknown): array
    {
        return $this->table(['traffic_type', 'sessions'], [
            ['paid', (string) $paid],
            ['direct', (string) $direct],
            ['organic', (string) $organic],
            ['unknown', (string) $unknown],
        ]);
    }

    /**
     * A fake store, not a fake response.
     *
     * ══ WHY THIS HAD TO BECOME FILTER-AWARE ══
     * The fetcher no longer enumerates every landing path — it queries the '/products/' and
     * '/collections/' SUBSETS and derives store-wide by subtraction (see fetchDay). So it now issues
     * FIVE kinds of call: the store split, the two subset splits, and the two subset page queries.
     * The old fake answered "any GROUP BY traffic_type" with the store totals, which would hand the
     * product-subset check the STORE's numbers and make every test lie.
     *
     * This serves the supplied rows the way a real store would: filtered by the CONTAINS predicate,
     * sliced by LIMIT/OFFSET, and with the subset totals COMPUTED from those same rows — so a test
     * cannot accidentally assert against a self-contradictory store.
     *
     * `$subsetOverrides` exists to inject a DELIBERATE disagreement (Shopify says the product subset
     * has 500 sessions; paging it yields 10), which is the only thing "incomplete" can now mean.
     *
     * @param  array<int, array<int, array<int, string>>>  $pages  rows, in pages (flattened here)
     * @param  array<string, array<string, int>>  $subsetOverrides  '/products/' => ['paid' => 500]
     */
    private function fakeShopify(array $totals, array $pages, array $subsetOverrides = []): void
    {
        $all = $pages === [] ? [] : array_merge(...$pages);

        Http::fake([
            '*/graphql.json' => function ($request) use ($totals, $all, $subsetOverrides) {
                $ql = (string) ($request->data()['variables']['q'] ?? '');

                $filter = null;
                foreach (['/products/', '/collections/'] as $needle) {
                    if (str_contains($ql, "CONTAINS '{$needle}'")) {
                        $filter = $needle;
                        break;
                    }
                }

                $matching = $filter === null
                    ? $all
                    : array_values(array_filter($all, fn (array $r) => str_contains($r[0], $filter)));

                // ── The per-traffic-type split (no landing_page_path dimension) ──
                if (! str_contains($ql, 'landing_page_path,')) {
                    if ($filter === null) {
                        return Http::response($totals, 200);   // the STORE total, as supplied
                    }

                    // A subset total. Computed from the same rows the page queries will serve, so
                    // the fake store is self-consistent — unless the test deliberately overrides it.
                    $byType = $subsetOverrides[$filter] ?? [];
                    if ($byType === []) {
                        foreach ($matching as $r) {
                            $byType[$r[1]] = ($byType[$r[1]] ?? 0) + (int) $r[2];
                        }
                    }

                    $rows = [];
                    foreach ($byType as $type => $sessions) {
                        $rows[] = [(string) $type, (string) $sessions];
                    }

                    return Http::response($this->table(['traffic_type', 'sessions'], $rows), 200);
                }

                // ── A page of the landing-path breakdown, honouring LIMIT/OFFSET like the real API ──
                $limit  = preg_match('/LIMIT (\d+)/', $ql, $m) ? (int) $m[1] : 1000;
                $offset = preg_match('/OFFSET (\d+)/', $ql, $m) ? (int) $m[1] : 0;

                return Http::response(
                    $this->table(
                        ['landing_page_path', 'traffic_type', 'sessions'],
                        array_slice($matching, $offset, $limit),
                    ),
                    200,
                );
            },
        ]);
    }

    public function test_it_pages_until_a_short_page_and_keeps_the_whole_tail(): void
    {
        // Page 1 is FULL (1000 rows) → there must be a page 2. A fetcher that stopped here
        // would look successful and quietly lose everything after row 1000.
        $pageOne = [];
        for ($i = 0; $i < 1000; $i++) {
            $pageOne[] = ['/products/p' . $i, 'paid', '2'];   // 1000 × 2 = 2000 sessions
        }
        // Page 2 is short → the end. These are the tail products a cap would have eaten.
        $pageTwo = [
            ['/products/tail-a', 'direct', '3'],
            ['/products/tail-b', 'organic', '1'],
        ];

        // Store total must equal 2000 + 3 + 1 = 2004 for the day to reconcile.
        $this->fakeShopify($this->totalsResponse(paid: 2000, direct: 3, organic: 1, unknown: 0), [$pageOne, $pageTwo]);

        $conn   = $this->conn();
        $result = app(SessionTrafficFetcher::class)->fetchDay($conn, self::DAY);

        $this->assertTrue($result['isComplete'], 'the paged sum must reconcile to the store total');
        $this->assertSame(2004, $result['pagedTotal']);
        $this->assertSame(2004, $result['storeTotal']);

        // 1002 distinct products, each one entity row.
        $this->assertCount(1002, $result['rows']);

        $byKey = collect($result['rows'])->keyBy(fn (array $r): string => $r['entity_key'] . ':' . $r['traffic_type']);
        $this->assertSame(3, $byKey['tail-a:direct']['sessions'], 'the tail survived paging');
        $this->assertSame(1, $byKey['tail-b:organic']['sessions']);
    }

    public function test_a_day_that_does_not_reconcile_is_stored_incomplete(): void
    {
        // Shopify says the PRODUCT subset has 500 sessions; paging that subset yields 10. Rows were
        // dropped, so the day is a lie and must be flagged.
        //
        // ══ WHAT "DOES NOT RECONCILE" NOW MEANS ══
        // It used to mean "Σ(every landing path) !== the store total". That test is UNACHIEVABLE on
        // a real store: every checkout mints a unique one-session URL, so a busy day has tens of
        // thousands of distinct paths, we hit the page ceiling, and the day failed by however many
        // rows we didn't reach. That is precisely the production bug (152,621 vs 151,485).
        //
        // We no longer enumerate the junk tail at all — store-wide is DERIVED by subtraction, so it
        // reconciles by construction. The only thing that can still go wrong is failing to page the
        // PRODUCT or COLLECTION subsets to their end, and that is what this now asserts.
        $this->fakeShopify(
            $this->totalsResponse(paid: 500, direct: 0, organic: 0, unknown: 0),
            [[['/products/jay', 'paid', '10']]],
            ['/products/' => ['paid' => 500]],   // Shopify: the product subset is 500. We got 10.
        );

        $conn   = $this->conn();
        $result = app(SessionTrafficFetcher::class)->fetchDay($conn, self::DAY);

        $this->assertFalse($result['isComplete'], 'the product subset was not paged to the end');

        // Rows are still stored — but every one is flagged, so the read layer renders "—", not 10.
        foreach ($result['rows'] as $row) {
            $this->assertFalse($row['is_complete']);
        }

        app(SessionTrafficSync::class)->syncDay($conn, self::DAY);
        $this->assertSame(0, SessionTrafficDaily::where('is_complete', true)->count());
    }

    public function test_a_busy_day_whose_junk_tail_exceeds_the_page_ceiling_now_RECONCILES(): void
    {
        // ══ THE PRODUCTION BUG, PINNED ══
        //     2026-06-28  Shopify: 152,621 sessions   our breakdown: 151,485   short 1,136
        // Every checkout mints a unique one-session landing URL, so the distinct-path count scales
        // with traffic without limit. The old fetcher paged ALL of them, hit its 25,000-row ceiling,
        // and came up short by exactly the rows it never reached — for THREE WEEKS, on the days that
        // matter most, with no way to fix it.
        //
        // Here: 1 product with 20 sessions, and 30,000 junk checkout URLs carrying 30,000 sessions.
        // Store total 30,020. The junk tail is FAR beyond any ceiling — and it no longer matters,
        // because we never ask for it.
        $junk = [];
        for ($i = 0; $i < 30_000; $i++) {
            $junk[] = ["/checkouts/cn/token-{$i}/en", 'direct', '1'];
        }

        $this->fakeShopify(
            $this->totalsResponse(paid: 20, direct: 30_000, organic: 0, unknown: 0),
            [array_merge([['/products/jay', 'paid', '20']], $junk)],
        );

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertTrue($result['isComplete'], 'the junk tail must not be able to break a day');
        $this->assertSame(30_020, $result['storeTotal']);
        $this->assertSame(30_020, $result['pagedTotal'], 'the parts still sum to the store total');

        $byKey = $this->byKey($result['rows']);

        // The product is measured exactly…
        $this->assertSame(20, $byKey['jay:paid']['sessions']);

        // …and all 30,000 junk URLs are accounted for in ONE derived store-wide row, without a
        // single one of them ever being downloaded.
        $this->assertSame(30_000, $byKey['store-wide:direct']['sessions']);
    }

    public function test_a_few_sessions_of_shopify_rounding_drift_does_NOT_blank_the_window(): void
    {
        // ══ THE PRODUCTION FAILURE THIS FIXES ══
        //     "Shopify says 27,504 paid sessions landed on product pages,
        //      but paging that list returned 27,503."
        //
        // ONE session in twenty-seven thousand. The subset check compares two INDEPENDENTLY computed
        // ShopifyQL aggregations of the same data (GROUP BY traffic_type vs GROUP BY
        // landing_page_path, traffic_type), and Shopify has never promised those are bit-identical.
        // Demanding exactness blanked a whole 30-day window — every product, every number the client
        // reads — to guard against a rounding difference.
        //
        // The real faults this check exists to catch were 1,136 / 8,861 / 26,671 sessions: three
        // orders of magnitude larger. A tolerance sits comfortably between the two classes.
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ["/products/p{$i}", 'paid', '10'];   // paged rows sum to 1,000
        }

        $this->fakeShopify(
            $this->totalsResponse(paid: 1_200, direct: 0, organic: 0, unknown: 0),
            [$rows],
            ['/products/' => ['paid' => 1_002]],   // Shopify's own subset total is 2 higher. Noise.
        );

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertTrue($result['isComplete'], '2 sessions of drift must not blank a month of data');

        // The invariant that actually matters is UNTOUCHED: the parts still sum to Shopify's own
        // store total exactly, because store-wide is DERIVED by subtraction. The drifted session is
        // not lost — it lands in store-wide, which is where an unattributable session belongs.
        $this->assertSame(1_200, $result['storeTotal']);
        $this->assertSame(1_200, $result['pagedTotal']);
    }

    public function test_a_MATERIALLY_short_subset_still_fails_the_day(): void
    {
        // The tolerance must not become a blindfold. A page ceiling or an unstable sort loses rows
        // by the thousand, and that has to keep failing closed — otherwise we would render a
        // confidently wrong number, which is the one outcome worse than rendering nothing.
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ["/products/p{$i}", 'paid', '10'];   // paged rows sum to 1,000
        }

        $this->fakeShopify(
            $this->totalsResponse(paid: 5_000, direct: 0, organic: 0, unknown: 0),
            [$rows],
            ['/products/' => ['paid' => 5_000]],   // Shopify says 5,000; we paged 1,000. 80% missing.
        );

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertFalse($result['isComplete'], '4,000 missing sessions is a broken query, not rounding');
        $this->assertNotEmpty($result['reasons']);
        $this->assertStringContainsString('4,000 missing', implode(' ', $result['reasons']));
    }

    public function test_unstable_paging_cannot_double_count_a_product(): void
    {
        // ══ THE SECOND PRODUCTION BUG, PINNED ══
        // Measured on Nude Project, 2026-06-28: "duplicated across pages: 8,861 sessions".
        // `ORDER BY sessions DESC` is not a total order — the tail is thousands of rows tied at 1
        // session — so Shopify returned some rows on TWO pages and skipped others. `paid` came back
        // 14 sessions OVER while `direct` came back 976 UNDER. On the same day.
        //
        // We now order by landing_page_path (stable) AND dedupe by (path, traffic_type). This fake
        // returns the SAME row on both pages — the worst case — and the product must still be
        // counted exactly once. Summing it twice would inflate the product and then over-subtract
        // from store-wide, driving the remainder negative: a wrong number that looks precise.
        $rows = [
            ['/products/p0', 'paid', '1'],
            ['/products/p1', 'paid', '1'],
            ['/products/p2', 'paid', '1'],
            ['/products/p0', 'paid', '1'],   // ← the same row again, as an unstable sort produces
        ];

        $this->fakeShopify(
            $this->totalsResponse(paid: 3, direct: 0, organic: 0, unknown: 0),
            [$rows],
            ['/products/' => ['paid' => 3]],   // Shopify's own truth: three product sessions
        );

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $byKey = $this->byKey($result['rows']);
        $this->assertSame(1, $byKey['p0:paid']['sessions'], 'a duplicated row must be counted ONCE');
        $this->assertTrue($result['isComplete']);
    }

    public function test_locale_variants_of_one_product_collapse_into_a_single_row(): void
    {
        // Three URLs, one product. If these stayed separate, Jay's real traffic (11) would be
        // reported as three unrelated rows of 6, 3 and 2 — and Jay would rank far too low.
        $this->fakeShopify(
            $this->totalsResponse(paid: 11, direct: 0, organic: 0, unknown: 0),
            [[
                ['/products/jay', 'paid', '6'],
                ['/es/products/jay', 'paid', '3'],
                ['/collections/best-sellers/products/jay', 'paid', '2'],
            ]],
        );

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertTrue($result['isComplete']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('jay', $result['rows'][0]['entity_key']);
        $this->assertSame('product', $result['rows'][0]['entity_type']);
        $this->assertSame(11, $result['rows'][0]['sessions']);
    }

    public function test_the_unmapped_tail_folds_into_one_store_wide_row_and_totals_still_reconcile(): void
    {
        // Every checkout mints a unique URL. Keyed raw, these would be thousands of one-session
        // rows. They collapse into ONE 'store-wide' row per traffic type — and, crucially, are
        // NOT dropped, so the parts still add up to the whole.
        $this->fakeShopify(
            $this->totalsResponse(paid: 5, direct: 6, organic: 0, unknown: 0),
            [[
                ['/products/jay', 'paid', '5'],
                ['/', 'direct', '3'],
                ['/checkouts/cn/AAA111/en-gb', 'direct', '1'],
                ['/checkouts/cn/BBB222/es-es', 'direct', '1'],
                ['/pages/returns', 'direct', '1'],
            ]],
        );

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertTrue($result['isComplete'], 'nothing was dropped, so it must still reconcile');
        $this->assertCount(2, $result['rows']);   // jay/paid + store-wide/direct

        $storeWide = collect($result['rows'])->firstWhere('entity_type', 'other');
        $this->assertSame('store-wide', $storeWide['entity_key']);
        $this->assertSame(6, $storeWide['sessions']);   // 3 + 1 + 1 + 1
    }

    public function test_the_rare_unattributed_type_is_kept_not_dropped(): void
    {
        // REGRESSION. `unattributed` is 7 sessions a YEAR on a 6.9M-session store, so a 30-day
        // probe never sees it — and the first cut of this fetcher therefore discarded it. That
        // discard was SILENT: `pagedTotal` is summed before the type filter, so reconciliation
        // still passed while the stored rows quietly summed to less than the store total.
        // The store total INCLUDES the unattributed 2 — as Shopify's own report does.
        Http::fake([
            '*/graphql.json' => function ($request) {
                $ql = (string) ($request->data()['variables']['q'] ?? '');
                if (str_contains($ql, 'GROUP BY traffic_type')) {
                    return Http::response($this->table(['traffic_type', 'sessions'], [
                        ['paid', '10'],
                        ['unattributed', '2'],
                    ]), 200);
                }

                return Http::response($this->table(['landing_page_path', 'traffic_type', 'sessions'], [
                    ['/products/jay', 'paid', '10'],
                    ['/products/jay', 'unattributed', '2'],
                ]), 200);
            },
        ]);

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertTrue($result['isComplete']);
        $this->assertSame(12, $result['pagedTotal']);

        $byType = collect($result['rows'])->keyBy('traffic_type');
        $this->assertSame(2, $byType['unattributed']['sessions'], 'the rare bucket must survive');

        // The invariant: the STORED rows sum to the store total. This is what silently broke.
        $this->assertSame(
            $result['storeTotal'],
            collect($result['rows'])->sum('sessions'),
            'stored rows must sum to the store total — nothing may be dropped on the floor',
        );
    }

    public function test_a_traffic_type_shopify_has_never_returned_fails_the_day_loudly(): void
    {
        // If Shopify adds a SIXTH type, we have nowhere to put it in the read model. It must
        // not be dropped while the day still reads green — that is the exact bug `unattributed`
        // caused. An unknown type poisons the day, so the UI shows "—" and we go look.
        Http::fake([
            '*/graphql.json' => function ($request) {
                $ql = (string) ($request->data()['variables']['q'] ?? '');
                if (str_contains($ql, 'GROUP BY traffic_type')) {
                    return Http::response($this->table(['traffic_type', 'sessions'], [
                        ['paid', '10'],
                        ['quantum', '5'],
                    ]), 200);
                }

                return Http::response($this->table(['landing_page_path', 'traffic_type', 'sessions'], [
                    ['/products/jay', 'paid', '10'],
                    ['/products/jay', 'quantum', '5'],
                ]), 200);
            },
        ]);

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        // It reconciles arithmetically (15 = 15) — and is STILL marked incomplete, because we
        // cannot faithfully represent it. Arithmetic agreement is not the same as understanding.
        $this->assertSame(15, $result['pagedTotal']);
        $this->assertSame(15, $result['storeTotal']);
        $this->assertFalse($result['isComplete'], 'an unrepresentable type must fail the day');
    }

    public function test_a_failed_totals_call_means_the_day_cannot_be_trusted(): void
    {
        // We could not establish what the truth WAS, so we cannot claim to have matched it.
        // A reconciliation that cannot be performed is a reconciliation that failed.
        Http::fake([
            '*/graphql.json' => function ($request) {
                $ql = (string) ($request->data()['variables']['q'] ?? '');
                if (str_contains($ql, 'GROUP BY traffic_type')) {
                    return Http::response(['errors' => [['message' => 'throttled']]], 200);
                }

                return Http::response(
                    $this->table(['landing_page_path', 'traffic_type', 'sessions'], [['/products/jay', 'paid', '10']]),
                    200,
                );
            },
        ]);

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertFalse($result['isComplete']);
        $this->assertNull($result['storeTotal']);
    }

    public function test_rows_are_read_by_COLUMN_NAME_not_by_position(): void
    {
        // THE production bug, pinned. ShopifyQL returns each row as an object keyed by column
        // name. The first cut read $row[2] by index — absent on a map — so every path came back
        // '', every session count read 0, the day "reconciled" (0 === 0), and 88 brands were
        // recorded as having had zero traffic for 90 days straight. With total confidence.
        //
        // This fixture is written as Shopify really sends it, deliberately by hand, so it cannot
        // drift back into agreeing with the parser instead of with the API.
        Http::fake([
            '*/graphql.json' => function ($request) {
                $ql = (string) ($request->data()['variables']['q'] ?? '');

                $rows = str_contains($ql, 'GROUP BY traffic_type')
                    ? [['traffic_type' => 'paid', 'sessions' => '455']]
                    : [['landing_page_path' => '/products/jay', 'traffic_type' => 'paid', 'sessions' => '455']];

                $cols = str_contains($ql, 'GROUP BY traffic_type')
                    ? [['name' => 'traffic_type'], ['name' => 'sessions']]
                    : [['name' => 'landing_page_path'], ['name' => 'traffic_type'], ['name' => 'sessions']];

                return Http::response([
                    'data' => ['shopifyqlQuery' => [
                        'tableData'   => ['columns' => $cols, 'rows' => $rows],
                        'parseErrors' => [],
                    ]],
                ], 200);
            },
        ]);

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertSame(455, $result['storeTotal'], 'sessions must be read by name, not index');
        $this->assertSame(455, $result['pagedTotal']);
        $this->assertTrue($result['isComplete']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('jay', $result['rows'][0]['entity_key']);
        $this->assertSame(455, $result['rows'][0]['sessions']);
    }

    public function test_a_row_missing_its_columns_fails_the_day_instead_of_reading_as_zero(): void
    {
        // If Shopify ever changes the row shape again, the day must break LOUDLY. Silently
        // skipping unreadable rows is what turned a parser bug into "your stores had no traffic".
        Http::fake([
            '*/graphql.json' => function ($request) {
                $ql = (string) ($request->data()['variables']['q'] ?? '');

                if (str_contains($ql, 'GROUP BY traffic_type')) {
                    return Http::response([
                        'data' => ['shopifyqlQuery' => [
                            'tableData'   => ['columns' => [['name' => 'traffic_type'], ['name' => 'sessions']],
                                'rows' => [['traffic_type' => 'paid', 'sessions' => '10']]],
                            'parseErrors' => [],
                        ]],
                    ], 200);
                }

                // A shape we do not understand — e.g. Shopify reverts to positional arrays.
                return Http::response([
                    'data' => ['shopifyqlQuery' => [
                        'tableData'   => ['columns' => [['name' => 'landing_page_path']],
                            'rows' => [['/products/jay', 'paid', '10']]],
                        'parseErrors' => [],
                    ]],
                ], 200);
            },
        ]);

        $result = app(SessionTrafficFetcher::class)->fetchDay($this->conn(), self::DAY);

        $this->assertFalse($result['isComplete'], 'an unreadable row shape must fail the day');
        $this->assertSame([], $result['rows']);

        // And the sync must therefore refuse to mark the day done.
        $this->assertNull(app(SessionTrafficSync::class)->syncDay($this->conn(), self::DAY));
    }

    public function test_a_genuinely_zero_session_day_is_DONE_and_writes_nothing(): void
    {
        // Store reports 0 sessions, and our paged rows also sum to 0 → they RECONCILE. The day is
        // established: it really had no traffic. Return 0 (done), write no rows — the read layer
        // then has no row for that date, which is correct and renders "—" rather than a fake 0.
        $this->fakeShopify($this->totalsResponse(0, 0, 0, 0), [[]]);

        $written = app(SessionTrafficSync::class)->syncDay($this->conn(), self::DAY);

        $this->assertSame(0, $written, 'reconciled-and-empty is a real zero day, not a failure');
        $this->assertSame(0, SessionTrafficDaily::count());
    }

    public function test_a_failed_query_returns_NULL_so_the_day_is_never_marked_done(): void
    {
        // THE incident. ShopifyQL reports a malformed query as an EMPTY TABLE, not an exception.
        // The old code collapsed that to `0 rows written`, identical to a genuinely quiet day —
        // so the backfill recorded 90 days × 88 brands as "done, no data" and a re-run would have
        // skipped them all. The gap would have been permanent and invisible.
        //
        // Empty rows + a store total we could NOT establish = we learned nothing. Return null.
        Http::fake([
            '*/graphql.json' => fn () => Http::response([
                'data' => [
                    'shopifyqlQuery' => [
                        'tableData'   => ['columns' => [], 'rows' => []],
                        'parseErrors' => ['Syntax no viable alternative at input LIMIT'],
                    ],
                ],
            ], 200),
        ]);

        $written = app(SessionTrafficSync::class)->syncDay($this->conn(), self::DAY);

        $this->assertNull($written, 'a parse error must NOT look like a quiet day');
        $this->assertSame(0, SessionTrafficDaily::count());
    }

    public function test_a_resync_removes_rows_that_no_longer_exist(): void
    {
        // Yesterday jay had traffic; today's re-pull says it didn't. Upsert alone would leave
        // the stale row standing and the day would keep reporting sessions Shopify no longer
        // reports. The sync deletes what it didn't touch.
        $this->fakeShopify(
            $this->totalsResponse(paid: 10, direct: 0, organic: 0, unknown: 0),
            [[['/products/jay', 'paid', '10']]],
        );

        $conn = $this->conn();
        app(SessionTrafficSync::class)->syncDay($conn, self::DAY);
        $this->assertSame(1, SessionTrafficDaily::where('entity_key', 'jay')->count());

        // Re-sync: Shopify now reports only the homepage for that day.
        $this->fakeShopify(
            $this->totalsResponse(paid: 0, direct: 4, organic: 0, unknown: 0),
            [[['/', 'direct', '4']]],
        );

        app(SessionTrafficSync::class)->syncDay($conn, self::DAY);

        $this->assertSame(0, SessionTrafficDaily::where('entity_key', 'jay')->count(), 'the stale row must go');
        $this->assertSame(1, SessionTrafficDaily::where('entity_key', 'store-wide')->count());
    }

    // ── The two bugs that made "Fill missing days" report success and change nothing ──────────

    public function test_a_day_that_writes_rows_but_does_not_reconcile_is_NOT_reported_as_success(): void
    {
        // THE BUG: syncDay used to return a ROW COUNT. A day that fails reconciliation still writes
        // rows — hundreds of them — so callers read "1,847 rows written" as success. The backfill
        // logged the day as filled, the repair button reported success, and the window stayed blank
        // click after click. A row count says NOTHING about whether the rows can be trusted.
        // Shopify says the product subset holds 500 sessions; paging it yields one row worth 10.
        // Rows were dropped, so the day cannot be trusted — even though it wrote a row.
        $this->fakeShopify(
            $this->totalsResponse(paid: 500, direct: 0, organic: 0, unknown: 0),
            [[['/products/jay', 'paid', '10']]],
            ['/products/' => ['paid' => 500]],
        );

        $result = app(SessionTrafficSync::class)->syncDay($this->conn(), self::DAY);

        // Rows WERE written…
        $this->assertGreaterThan(0, $result->rowsWritten);
        $this->assertGreaterThan(0, SessionTrafficDaily::count());

        // …and the day is still UNUSABLE. THIS is the assertion that would have caught the lie:
        // the old contract could only say "rows > 0", which is not an answer to "can I trust this?".
        $this->assertTrue($result->established, 'we did get an answer out of Shopify');
        $this->assertFalse($result->complete, 'a day that does not reconcile is NOT a success');

        // The verdict is recorded, and it says the day cannot be used.
        $day = SessionTrafficDay::where('date', self::DAY)->firstOrFail();
        $this->assertFalse((bool) $day->is_complete);

        // Nothing in the breakdown table is readable, so the read gate renders "—", never a number.
        $this->assertSame(0, SessionTrafficDaily::where('is_complete', true)->count());
    }

    public function test_a_genuinely_quiet_day_is_recorded_as_complete_even_though_it_has_no_rows(): void
    {
        // THE OTHER BUG: completeness was inferred from the existence of breakdown rows. A day with
        // ZERO sessions writes no rows — so it could never be counted as complete, and any window
        // containing one quiet day was blanked FOREVER. No backfill could fix it, because the
        // backfill was correct: the day was done, and it was empty. Unfixable by construction.
        $this->fakeShopify(
            $this->totalsResponse(paid: 0, direct: 0, organic: 0, unknown: 0),
            [[]],   // no landing-page rows at all
        );

        $result = app(SessionTrafficSync::class)->syncDay($this->conn(), self::DAY);

        $this->assertTrue($result->established);
        $this->assertTrue($result->complete, '0 === 0 reconciles: this day is DONE, not missing');
        $this->assertSame(0, $result->rowsWritten);
        $this->assertSame(0, SessionTrafficDaily::count(), 'no traffic means no breakdown rows — correct');

        // The day is nonetheless RECORDED as complete, which is the whole point: the read gate
        // counts this, so a window containing a quiet day is no longer blank forever.
        $day = SessionTrafficDay::where('date', self::DAY)->firstOrFail();
        $this->assertTrue((bool) $day->is_complete);
        $this->assertSame(0, $day->paged_total);
    }

    public function test_a_failed_fetch_does_not_overwrite_a_previously_good_day(): void
    {
        // A transient timeout must not destroy data we already have. "We could not reach Shopify"
        // and "Shopify says this day is empty" are different facts, and only one of them is a
        // reason to change what is stored.
        $this->fakeShopify(
            $this->totalsResponse(paid: 11, direct: 0, organic: 0, unknown: 0),
            [[['/products/jay', 'paid', '11']]],
        );
        $good = app(SessionTrafficSync::class)->syncDay($this->conn(), self::DAY);
        $this->assertTrue($good->complete);

        // Now the endpoint falls over.
        Http::fake(['*' => Http::response([], 500)]);

        $result = app(SessionTrafficSync::class)->syncDay($this->conn(), self::DAY);

        $this->assertFalse($result->established, 'we learned nothing');
        $this->assertFalse($result->complete);

        // The good day survives untouched.
        $this->assertSame(1, SessionTrafficDaily::where('entity_key', 'jay')->count());
        $this->assertTrue((bool) SessionTrafficDay::where('date', self::DAY)->firstOrFail()->is_complete);
    }
}
