<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\SessionTrafficDaily;
use App\Services\Aggregation\InventoryQuery;
use App\Support\LandingPathMapper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bosco item B — sessions by traffic type per landing entity.
 *
 * The two invariants worth the most here:
 *
 *  1. Landing paths RESOLVE and COLLAPSE. /es/products/jay, /fr/products/jay and
 *     /collections/best-sellers/products/jay are all the SAME product. Getting this wrong
 *     splits one product's sessions across several rows and under-reports every one of them.
 *
 *  2. The window FAILS CLOSED. A window with any unreconciled or missing day reports nothing —
 *     not a partial sum. A 30-day window holding 12 synced days would under-report every
 *     product by ~60% while looking perfectly precise, and the table would then be SORTED by
 *     that wrong number.
 */
final class SessionTrafficTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function brand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => 'UTC', 'status' => 'active']);
    }

    private function row(Brand $b, string $date, string $type, string $key, string $traffic, int $n, bool $complete = true): void
    {
        SessionTrafficDaily::create([
            'brand_id'     => $b->id,
            'date'         => $date,
            'entity_type'  => $type,
            'entity_key'   => $key,
            'traffic_type' => $traffic,
            'sessions'     => $n,
            'is_complete'  => $complete,
            'pulled_at'    => now(),
        ]);
    }

    /** Same shape InventoryQueryTest seeds — status lowercased, as shopify:sync-catalog stores it. */
    private function product(Brand $b, string $handle, string $title, int $stock = 50): void
    {
        DB::table('product_catalog')->insert([
            'brand_id'        => $b->id,
            'product_id'      => 'gid://shopify/Product/' . random_int(1000, 999999),
            'handle'          => $handle,
            'title'           => $title,
            'product_type'    => null,
            'status'          => 'active',
            'tags'            => json_encode([]),
            'variant_count'   => 1,
            'total_inventory' => $stock,
            'variants'        => json_encode([['t' => 'Default', 'q' => $stock]]),
            'captured_at'     => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // The mapper — every case below was observed on a real store (2026-07-12)
    // ---------------------------------------------------------------------

    public function test_landing_paths_resolve_to_the_right_entity(): void
    {
        $cases = [
            // path                                            type          key
            ['/products/jay',                                  'product',    'jay'],
            ['/es/products/jay',                               'product',    'jay'],      // locale
            ['/fr-fr/products/jay',                            'product',    'jay'],      // region locale
            ['/collections/best-sellers/products/lucrecia',    'product',    'lucrecia'], // the PRODUCT wins
            ['/products/jay?variant=42',                       'product',    'jay'],      // query string
            ['/products/jay#reviews',                          'product',    'jay'],      // fragment
            ['/products/NARNIA-PINK',                          'product',    'narnia-pink'], // same store served both cases
            ['/products/isabella-ribbon-aqua)',                'product',    'isabella-ribbon-aqua'], // malformed link
            ['/collections/new-in',                            'collection', 'new-in'],
            ['/es/collections/woman',                          'collection', 'woman'],
            ['/',                                              'other',      'store-wide'],
            ['/es',                                            'other',      'store-wide'],
            ['/pages/returns',                                 'other',      'store-wide'],
            ['/search',                                        'other',      'store-wide'],
            ['/cart',                                          'other',      'store-wide'],
            // Every checkout mints a unique URL — this is why raw landing_path is not a key.
            ['/checkouts/cn/hWNEHT1fJycojLAkIESuSzUh/en-gb',    'other',      'store-wide'],
        ];

        foreach ($cases as [$path, $type, $key]) {
            $got = LandingPathMapper::resolve($path);
            $this->assertSame($type, $got['type'], "type for {$path}");
            $this->assertSame($key, $got['key'], "key for {$path}");
        }
    }

    public function test_a_collection_nested_product_is_a_product_not_a_collection(): void
    {
        // Counting /collections/x/products/y as a COLLECTION view would inflate the collection
        // and starve the product — the exact opposite of what Inventory Intelligence is for.
        $this->assertSame('lucrecia', LandingPathMapper::productHandle('/collections/mary-jane/products/lucrecia'));
        $this->assertNull(LandingPathMapper::collectionHandle('/collections/mary-jane/products/lucrecia'));
    }

    // ---------------------------------------------------------------------
    // The read path
    // ---------------------------------------------------------------------

    public function test_sessions_collapse_across_locales_and_split_by_traffic_type(): void
    {
        // Window = last7 ending yesterday. Freeze so the window is deterministic.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 09:00:00', 'UTC'));

        $brand = $this->brand();
        $this->product($brand, 'jay', 'Jay Ballerina');

        // last7 = 2026-07-03 .. 2026-07-09 (7 days ending yesterday). Every day must be
        // present and complete, or the gate closes — so seed all seven.
        foreach (['2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07'] as $d) {
            $this->row($brand, $d, 'product', 'jay', 'paid', 0);
        }

        // The interesting days. NOTE the rows are already entity-resolved — /es/products/jay
        // and /products/jay both arrive as entity_key 'jay' and SUM.
        $this->row($brand, '2026-07-08', 'product',    'jay',        'paid',   15);
        $this->row($brand, '2026-07-08', 'product',    'jay',        'direct',  3);
        $this->row($brand, '2026-07-08', 'other',      'store-wide', 'direct', 100);
        $this->row($brand, '2026-07-09', 'product',    'jay',        'paid',    2);
        $this->row($brand, '2026-07-09', 'collection', 'new-in',     'paid',   40);

        $out = app(InventoryQuery::class)->run($brand->fresh(), ['period' => 'last7']);

        $s = $out['sessions'];
        $this->assertTrue($s['complete']);
        $this->assertSame(7, $s['windowDays']);
        $this->assertSame(7, $s['completeDays']);

        // jay: paid 15 + 2 = 17, direct 3 → 20 total.
        $jay = collect($out['products'])->firstWhere('handle', 'jay');
        $this->assertSame(20, $jay['sessions']);
        $this->assertSame(17, $jay['sessionsByType']['paid']);
        $this->assertSame(3, $jay['sessionsByType']['direct']);
        $this->assertSame(0, $jay['sessionsByType']['organic']);

        // The collection landing and the homepage landing are NOT the product's. They live in
        // the store-wide row: paid 40, direct 100.
        $this->assertSame(40, $s['storeWide']['paid']);
        $this->assertSame(100, $s['storeWide']['direct']);

        // Store totals reconcile: paid 17+40 = 57, direct 3+100 = 103. Grand total 160.
        $this->assertSame(57, $s['byType']['paid']);
        $this->assertSame(103, $s['byType']['direct']);
        $this->assertSame(160, $s['total']);
        $this->assertSame(20, $s['productTotal']);

        // And the parts add up to the whole — the property the sync's reconciliation enforces.
        $this->assertSame(
            $s['total'],
            $s['productTotal'] + array_sum($s['storeWide']),
            'product sessions + store-wide must equal the store total, or something was dropped',
        );
    }

    public function test_a_single_missing_day_hides_every_session_number(): void
    {
        // THE gate. Six of seven days synced looks like plenty — and a sum over them would be
        // ~14% low on every product, silently, while the table sorted by it.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 09:00:00', 'UTC'));

        $brand = $this->brand();
        $this->product($brand, 'jay', 'Jay Ballerina');

        foreach (['2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07', '2026-07-08'] as $d) {
            $this->row($brand, $d, 'product', 'jay', 'paid', 10);
        }
        // 2026-07-09 is simply absent.

        $out = app(InventoryQuery::class)->run($brand->fresh(), ['period' => 'last7']);

        $this->assertFalse($out['sessions']['complete']);
        $this->assertSame(6, $out['sessions']['completeDays']);
        $this->assertSame(7, $out['sessions']['windowDays']);
        $this->assertNull($out['sessions']['byType']);
        $this->assertNull($out['sessions']['total']);

        $jay = collect($out['products'])->firstWhere('handle', 'jay');
        $this->assertNull($jay['sessions'], 'a gap in the window must render "—", never a short sum');
        $this->assertNull($jay['sessionsByType']);

        // But freshness is still reported — the operator learns how far the data DOES reach.
        $this->assertSame('2026-07-08', $out['sessions']['through']);
        $this->assertSame('2026-07-08', $out['dataThrough']['sessions']);
    }

    public function test_an_unreconciled_day_counts_as_missing(): void
    {
        // is_complete = false means the paged rows did not add up to Shopify's own store total
        // for that day. Those rows are SHORT. Treating them as usable is how a wrong number
        // gets shipped with a confident face.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 09:00:00', 'UTC'));

        $brand = $this->brand();
        $this->product($brand, 'jay', 'Jay Ballerina');

        foreach (['2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07', '2026-07-08'] as $d) {
            $this->row($brand, $d, 'product', 'jay', 'paid', 10);
        }
        $this->row($brand, '2026-07-09', 'product', 'jay', 'paid', 10, complete: false);

        $out = app(InventoryQuery::class)->run($brand->fresh(), ['period' => 'last7']);

        $this->assertFalse($out['sessions']['complete']);
        $this->assertSame(6, $out['sessions']['completeDays']);   // the incomplete day doesn't count
        $this->assertNull(collect($out['products'])->firstWhere('handle', 'jay')['sessions']);
    }

    public function test_a_covered_window_with_no_landings_is_a_real_zero(): void
    {
        // The distinction the whole design turns on: 0 means "nobody landed here", "—" means
        // "we don't know". A product with a fully-synced window and no traffic is a 0.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 09:00:00', 'UTC'));

        $brand = $this->brand();
        $this->product($brand, 'jay', 'Jay Ballerina');
        $this->product($brand, 'ghost', 'Ghost Loafer');   // never landed on

        foreach (['2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09'] as $d) {
            $this->row($brand, $d, 'product', 'jay', 'paid', 5);
        }

        $out = app(InventoryQuery::class)->run($brand->fresh(), ['period' => 'last7']);
        $this->assertTrue($out['sessions']['complete']);

        $ghost = collect($out['products'])->firstWhere('handle', 'ghost');
        $this->assertSame(0, $ghost['sessions']);                       // a real zero…
        $this->assertSame(0, $ghost['sessionsByType']['paid']);
        $this->assertNotNull($ghost['sessionsByType'], '…not a null');
    }

    public function test_all_five_shopify_traffic_types_are_reported_including_the_rare_one(): void
    {
        // REGRESSION. The first cut of this feature reported four types and dropped
        // `unattributed`, because a 30-day probe never returned it. Over a FULL YEAR of a real
        // store it is there: paid 3,117,263 · direct 2,599,142 · unknown 757,967 ·
        // organic 457,105 · unattributed 7 — summing exactly to the 6,931,484 store total.
        // 0.0001% is rare. Rare is not absent, and dropping it loses real sessions.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 09:00:00', 'UTC'));

        $brand = $this->brand();
        $this->product($brand, 'jay', 'Jay Ballerina');

        foreach (['2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09'] as $d) {
            $this->row($brand, $d, 'product', 'jay', 'paid', 1);
        }
        $this->row($brand, '2026-07-09', 'product', 'jay', 'unattributed', 3);

        $out = app(InventoryQuery::class)->run($brand->fresh(), ['period' => 'last7']);

        $this->assertSame(
            ['paid', 'direct', 'organic', 'unknown', 'unattributed'],
            array_keys($out['sessions']['byType']),
        );
        $this->assertSame(3, $out['sessions']['byType']['unattributed']);
        $this->assertSame(10, $out['sessions']['total'], '7 paid + 3 unattributed — none dropped');

        $jay = collect($out['products'])->firstWhere('handle', 'jay');
        $this->assertSame(3, $jay['sessionsByType']['unattributed']);
        $this->assertSame(10, $jay['sessions']);
    }
}
