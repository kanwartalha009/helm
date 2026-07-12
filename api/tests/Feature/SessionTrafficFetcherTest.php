<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\SessionTrafficDaily;
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

    /** A ShopifyQL tableData payload. */
    private function table(array $columns, array $rows): array
    {
        return [
            'data' => [
                'shopifyqlQuery' => [
                    'tableData'  => [
                        'columns' => array_map(static fn (string $c): array => ['name' => $c], $columns),
                        'rows'    => $rows,
                    ],
                    'parseErrors' => [],
                ],
            ],
        ];
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
     * Route each faked GraphQL call by looking at the ShopifyQL in the request body. The
     * fetcher issues one totals call and then N page calls.
     *
     * @param array<int, array<int, array<int, string>>> $pages rows per page, in order
     */
    private function fakeShopify(array $totals, array $pages): void
    {
        $pageNo = 0;

        Http::fake([
            '*/graphql.json' => function ($request) use ($totals, $pages, &$pageNo) {
                $ql = (string) ($request->data()['variables']['q'] ?? '');

                if (str_contains($ql, 'GROUP BY traffic_type')) {
                    return Http::response($totals, 200);
                }

                $rows = $pages[$pageNo] ?? [];
                $pageNo++;

                return Http::response(
                    $this->table(['landing_page_path', 'traffic_type', 'sessions'], $rows),
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
        // Shopify says 500 sessions; the rows we got add to 10. Something was dropped. The ONLY
        // safe response is to admit it — a 10 rendered as fact would be a lie with a decimal point.
        $this->fakeShopify(
            $this->totalsResponse(paid: 500, direct: 0, organic: 0, unknown: 0),
            [[['/products/jay', 'paid', '10']]],
        );

        $conn   = $this->conn();
        $result = app(SessionTrafficFetcher::class)->fetchDay($conn, self::DAY);

        $this->assertFalse($result['isComplete']);
        $this->assertSame(500, $result['storeTotal']);
        $this->assertSame(10, $result['pagedTotal']);

        // The rows are still stored — but every one of them is flagged, so the read layer
        // renders "—" rather than 10.
        foreach ($result['rows'] as $row) {
            $this->assertFalse($row['is_complete']);
        }

        app(SessionTrafficSync::class)->syncDay($conn, self::DAY);
        $this->assertSame(0, SessionTrafficDaily::where('is_complete', true)->count());
        $this->assertSame(1, SessionTrafficDaily::where('is_complete', false)->count());
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

    public function test_an_empty_day_writes_nothing_rather_than_zeroes(): void
    {
        // A day we learned nothing about must not be painted as a flatline. No rows written →
        // the read layer has no row for that date → the window fails its completeness gate →
        // the UI shows "—". Writing zeroes here would turn a sync gap into "nobody visited".
        $this->fakeShopify($this->totalsResponse(0, 0, 0, 0), [[]]);

        $conn    = $this->conn();
        $written = app(SessionTrafficSync::class)->syncDay($conn, self::DAY);

        $this->assertSame(0, $written);
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
}
