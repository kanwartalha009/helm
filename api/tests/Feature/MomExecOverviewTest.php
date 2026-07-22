<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Platforms\Shopify\RevenueFetcher;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * M5 end-to-end completion (Kanwar, 2026-07-15 — "complete the report end to
 * end... once we sync all data for 1 brand"): the S-EX executive-overview
 * tiles that used to render "not built yet" now light up from their real
 * sources (incl. the new-vs-returning split, which replaced the retired
 * standalone S3 section). This test proves each wired tile appears when
 * its source has data — sessions/CVR from shopify_funnel_daily, new/returning
 * + CAC from the (mocked) live ShopifyQL customer split, email only when
 * Klaviyo is connected — and stays honestly absent otherwise.
 *
 * The live ShopifyQL call (RevenueFetcher::customersByMonthRange, raw Guzzle —
 * not Http::fake-able) is exercised by binding a Mockery double into the
 * container, so CustomerMix resolves the double and the wiring is tested
 * without a network call.
 */
class MomExecOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfMonth()->subMonth();
    }

    private function makeBrand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);
    }

    private function actingMasterAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
    }

    private function seedShopifyConnection(Brand $brand): void
    {
        (new PlatformConnection())->forceFill([
            'brand_id' => $brand->id, 'platform' => 'shopify',
            'external_id' => "shop-{$brand->id}", 'status' => 'active',
            'credentials' => ['access_token' => 'tok'],
        ])->save();
    }

    /** Bind a RevenueFetcher double returning a canned monthly customer split. */
    private function mockCustomerCounts(int $customers, int $returning, int $orders): void
    {
        $ym = $this->monthStart()->format('Y-m');
        $mock = Mockery::mock(RevenueFetcher::class);
        $mock->shouldReceive('customersByMonthRange')
            ->andReturn([$ym => ['total' => null, 'net' => null, 'orders' => $orders, 'customers' => $customers, 'returning' => $returning]]);
        $this->app->instance(RevenueFetcher::class, $mock);
    }

    public function test_sex_wires_sessions_and_conversion_rate_from_funnel(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $date  = $this->monthStart()->addDays(3)->toDateString();

        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 10,
        ]);
        // 500 sessions, 25 completed checkouts -> CVR 5.0%.
        DB::table('shopify_funnel_daily')->insert([
            'brand_id' => $brand->id, 'date' => $date, 'dimension' => 'country',
            'segment_key' => 'ES', 'segment_label' => 'Spain',
            'sessions' => 500, 'completed_checkout' => 25, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month={$this->monthStart()->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEquals(500, $res->json('tiles.sessions.value'));
        $this->assertEquals(5.0, $res->json('tiles.conversionRate.value'));
    }

    public function test_s2_carries_a_modeled_new_vs_returning_sales_split(): void
    {
        // Kanwar, 2026-07-15: the new/returning SALES split (v1's new × AOV
        // estimate) rides under S2's total-sales chart. Shopify can't split
        // sales by customer type, so it's Modeled — asserted labelled as such.
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedShopifyConnection($brand);
        $this->mockCustomerCounts(customers: 100, returning: 40, orders: 120);

        // Real month total 1000 over 100 orders → AOV 10; 60 new × 10 = 600 new,
        // returning = 1000 − 600 = 400.
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $this->monthStart()->addDays(3)->toDateString(),
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 100,
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S2?month={$this->monthStart()->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame('modeled', $res->json('customerSalesSplit.basis'));
        $this->assertEquals(600.0, $res->json('customerSalesSplit.new.sales'));
        $this->assertEquals(400.0, $res->json('customerSalesSplit.returning.sales'));
        $this->assertEquals(60, $res->json('customerSalesSplit.new.customers'));
        $this->assertEquals(40, $res->json('customerSalesSplit.returning.customers'));
        $this->assertNotEmpty($res->json('customerSalesSplit.method'));

        // The DAILY series backs the new/returning graph (same x-axis as sales):
        // each day's real revenue is split by the month's new-share (600/1000 =
        // 60%). Only one day was seeded (revenue 1000) → new 600, returning 400.
        $daily = collect($res->json('customerSalesDaily'));
        $this->assertCount(1, $daily);
        $this->assertEquals(600.0, $daily->first()['new']);
        $this->assertEquals(400.0, $daily->first()['returning']);
    }

    public function test_s2_uses_real_shopify_customer_type_split_when_available(): void
    {
        // Kanwar, 2026-07-21: client wanted ACTUAL new-vs-returning revenue, not
        // the estimate. Shopify DOES expose it (dimension new_or_returning_customer),
        // so when the live split resolves, S2 carries Shopify's OWN figures and is
        // labelled 'verified' — NOT the blended-AOV estimate.
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedShopifyConnection($brand);

        $ym = $this->monthStart()->format('Y-m');
        $mock = Mockery::mock(RevenueFetcher::class);
        // Counts still come from the same source (real).
        $mock->shouldReceive('customersByMonthRange')
            ->andReturn([$ym => ['total' => null, 'net' => null, 'orders' => 120, 'customers' => 100, 'returning' => 40]]);
        // The REAL revenue split by customer type (Shopify's own net/total sales).
        $mock->shouldReceive('customerTypeSalesForWindow')
            ->andReturn(['new' => ['net' => 1800.0, 'total' => 2000.0], 'returning' => ['net' => 900.0, 'total' => 1000.0]]);
        $this->app->instance(RevenueFetcher::class, $mock);

        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $this->monthStart()->addDays(3)->toDateString(),
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 100,
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S2?month={$ym}")
            ->assertOk()->assertJsonPath('status', 'ok');

        // Verified, not modeled — the whole point of the client request.
        $this->assertSame('verified', $res->json('customerSalesSplit.basis'));
        // Displays Shopify's own total_sales per type (the figure the client
        // checks against in Shopify Analytics) plus net for after-returns.
        $this->assertEquals(2000.0, $res->json('customerSalesSplit.new.sales'));
        $this->assertEquals(1000.0, $res->json('customerSalesSplit.returning.sales'));
        $this->assertEquals(1800.0, $res->json('customerSalesSplit.new.net'));
        $this->assertEquals(900.0, $res->json('customerSalesSplit.returning.net'));
        // % is each type's SHARE OF SALES: new 2000/3000 = 66.7, returning 33.3.
        $this->assertEquals(66.7, $res->json('customerSalesSplit.new.pct'));
        $this->assertEquals(33.3, $res->json('customerSalesSplit.returning.pct'));
        // Customer counts still real (from CustomerMix): new = 100 − 40 = 60.
        $this->assertEquals(60, $res->json('customerSalesSplit.new.customers'));
        $this->assertEquals(40, $res->json('customerSalesSplit.returning.customers'));

        // Daily series allocates real revenue by the REAL new-share (2000/3000):
        // the one seeded day (rev 1000) → new ≈ 666.67, returning ≈ 333.33.
        $daily = collect($res->json('customerSalesDaily'));
        $this->assertCount(1, $daily);
        $this->assertEqualsWithDelta(666.67, $daily->first()['new'], 0.01);
        $this->assertEqualsWithDelta(333.33, $daily->first()['returning'], 0.01);
    }

    public function test_sex_wires_new_vs_returning_and_cac_from_customer_split(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedShopifyConnection($brand);
        $this->mockCustomerCounts(customers: 100, returning: 40, orders: 120);

        $date = $this->monthStart()->addDays(3)->toDateString();
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'meta', 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'spend' => 600,
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month={$this->monthStart()->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        // new = 100 - 40 = 60 -> new% = 60.0; the RETURNING share (40%) rides
        // alongside so S-EX shows the full split (Kanwar, 2026-07-15 — S3 retired).
        $this->assertEquals(60.0, $res->json('tiles.newVsReturningPct.value'));
        $this->assertEquals(40.0, $res->json('tiles.newVsReturningPct.returningPct'));
        $this->assertEquals(60, $res->json('tiles.newVsReturningPct.newCount'));
        $this->assertEquals(40, $res->json('tiles.newVsReturningPct.returningCount'));
        // CAC = 600 spend / 60 new = 10.0
        $this->assertEquals(10.0, $res->json('tiles.cac.value'));
    }

    public function test_sex_carries_goals_vs_target_moved_from_the_retired_sgoals_section(): void
    {
        // Kanwar, 2026-07-15 — "Goals vs actual move it to Executive overview
        // cards": the standalone S-GOALS section was retired and its revenue/ROAS
        // vs-target progress now rides in the S-EX payload as `goals`. Same Pacing
        // engine, so a target set for the brand lights up the exec goal cards.
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart()->format('Y-m');

        // No target yet -> goals omitted entirely (never a fabricated 0%-of-goal).
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month={$month}")->assertOk();
        $this->assertNull($res->json('goals'));

        // Standing default target + real revenue -> goals.revenue lights up.
        BrandTarget::create(['brand_id' => $brand->id, 'month' => null, 'revenue_target' => 1000, 'roas_target' => 3]);
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $this->monthStart()->addDays(2)->toDateString(),
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'total_sales' => 400, 'refunds_amount' => 0, 'orders' => 4,
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month={$month}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertEquals(1000.0, $res->json('goals.revenue.target'));
        $this->assertFalse($res->json('goals.revenue.goalHit')); // 400 of 1000
    }

    public function test_sex_email_tile_only_appears_when_klaviyo_connected(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $date  = $this->monthStart()->addDays(3)->toDateString();

        DB::table('email_daily_metrics')->insert([
            'brand_id' => $brand->id, 'date' => $date, 'source' => 'flow', 'source_id' => 'f1', 'source_name' => 'Welcome',
            'conversion_value' => 250, 'conversions' => 3, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        // No Klaviyo key: email omitted from BOTH tiles and unavailable.
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month={$this->monthStart()->format('Y-m')}")->assertOk();
        $this->assertNull($res->json('tiles.emailRevenue'));
        $this->assertArrayNotHasKey('emailRevenue', $res->json('unavailable'));

        // Connect Klaviyo: the email tile now renders the attributed revenue.
        app(PlatformCredentialService::class)->set('klaviyo', 'private_key', 'pk_test', brandId: (int) $brand->id);
        $res2 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month={$this->monthStart()->format('Y-m')}")->assertOk();
        $this->assertEquals(250.0, $res2->json('tiles.emailRevenue.value'));
    }

    public function test_sex_honors_a_custom_day_range_and_compares_the_same_range_last_year(): void
    {
        // Kanwar, 2026-07-17 — custom date ranges. A period='custom' from/to
        // request narrows every range-compatible section (here S-EX) to that day
        // window and, by default, compares the SAME dates a year earlier.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', self::TZ));
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $seed = function (string $date, float $sales) use ($brand): void {
            DB::table('daily_metrics')->insert([
                'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $date,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                'total_sales' => $sales, 'refunds_amount' => 0, 'orders' => 1,
            ]);
        };
        $seed('2026-06-05', 700); // inside Jun 1–14
        $seed('2026-06-20', 300); // outside the range, same month
        $seed('2025-06-05', 500); // same range, last year (the YoY comparison)

        // Custom range Jun 1–14 2026 → revenue is ONLY the in-range 700, and the
        // comparison is the same range in 2025 (500), not the whole month.
        $range = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?period=custom&from=2026-06-01&to=2026-06-14&compare=last_year")
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertEquals(700.0, $range->json('tiles.revenue.value'));
        $this->assertEquals(500.0, $range->json('tiles.revenue.compare'));

        // Sanity: the whole month (month mode) sums both June days → 1000, proving
        // the range genuinely narrows rather than the seed being incomplete.
        $whole = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month=2026-06")
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertEquals(1000.0, $whole->json('tiles.revenue.value'));

        CarbonImmutable::setTestNow();
    }

    public function test_financial_matrix_splits_into_week_on_week_columns_under_a_custom_range(): void
    {
        // Kanwar, 2026-07-20 — under a custom range the matrices show WEEK-ON-WEEK
        // columns (one ISO week per column) instead of a monthly grid or a single
        // range-vs-last-year collapse. Jun 1 2026 is a Monday, so Jun 1–14 splits
        // into exactly two weeks: Jun 1–7 and Jun 8–14. Revenue lands 700 in wk1,
        // 0 in wk2, Total 700. Last-year data is irrelevant to a progression view.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', self::TZ));
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $seed = function (string $date, float $sales) use ($brand): void {
            DB::table('daily_metrics')->insert([
                'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $date,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                'total_sales' => $sales, 'refunds_amount' => 0, 'orders' => 1,
            ]);
        };
        $seed('2026-06-05', 700);  // week 1 (Jun 1–7)
        $seed('2026-06-20', 300);  // outside the range
        $seed('2025-06-05', 500);  // same range last year — ignored in a weekly progression

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S1?period=custom&from=2026-06-01&to=2026-06-14&compare=last_year")
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('range', true);

        // Weekly full-parity payload (Kanwar, 2026-07-21): the matrix now renders
        // through the SAME S1 renderer as month mode — one ROW PER WEEK in
        // currentYearRows (all the detailed columns), with weekHeaders carrying
        // the ISO week for the two-line "W23 / 1–7 Jun" header. Jun 1 2026 is a
        // Monday in ISO week 23; the second week is 24.
        $this->assertTrue($res->json('weekly'));
        $weekHeaders = $res->json('weekHeaders');
        $this->assertCount(2, $weekHeaders);
        $this->assertSame(23, $weekHeaders[0]['week']);
        $this->assertSame(24, $weekHeaders[1]['week']);

        $rows = $res->json('currentYearRows');
        $this->assertCount(2, $rows);
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame('2026-06-01', $rows[0]['month']); // week-start rowKey
        $this->assertEquals(700.0, $rows[0]['revenue']);    // week 1 (Jun 1–7)
        $this->assertEquals(0.0, $rows[1]['revenue']);      // week 2 (Jun 8–14)
        // Δ Revenue is WEEK-over-week: wk1 has no prior week (null), wk2 = (0−700)/700 = −100%.
        $this->assertNull($rows[0]['deltaRevenuePct']);
        $this->assertEquals(-100.0, $rows[1]['deltaRevenuePct']);
        // Customer-split columns need whole months → null (render "—"), never faked.
        $this->assertNull($rows[0]['new']);
        $this->assertNull($rows[0]['cac']);
        // Weekly is a single table — no prior-year block, and the goal column hides.
        $this->assertSame([], $res->json('priorYearRows'));
        $this->assertFalse($res->json('hasGoals'));

        CarbonImmutable::setTestNow();
    }

    public function test_categories_split_into_week_on_week_revenue_columns_under_a_custom_range(): void
    {
        // Kanwar, 2026-07-20 — S7 categories show week-on-week revenue per segment
        // under a custom range. Bags 800 in week 1 (Jun 1–7), nothing in week 2,
        // Total 800. A summed Total footer row sits under the segments.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', self::TZ));
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $seedCat = function (string $date, string $cat, float $sales) use ($brand): void {
            DB::table('commerce_daily_metrics')->insert([
                'brand_id' => $brand->id, 'date' => $date, 'dimension_type' => 'category',
                'dimension_key' => $cat, 'dimension_label' => $cat, 'orders' => 1,
                'total_sales' => $sales, 'refunds_amount' => 0, 'currency' => 'EUR',
                'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        };
        $seedCat('2026-06-05', 'Bags', 800);     // week 1
        $seedCat('2026-06-06', 'Wallets', 200);  // week 1
        $seedCat('2026-06-20', 'Bags', 500);     // outside the range
        $seedCat('2025-06-05', 'Bags', 400);     // last year — ignored

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S7?period=custom&from=2026-06-01&to=2026-06-14&compare=last_year")
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('range', true);

        // Weekly full-parity payload (Kanwar, 2026-07-21): the SAME S7 renderer as
        // month mode — per-segment rows with a per-week `monthly[]` + Total/Share/
        // ΔYoY/ΔMoM — driven by `months` / `weekHeaders`, with weeks as the periods.
        $this->assertTrue($res->json('weekly'));
        $this->assertCount(2, $res->json('weekHeaders'));
        $this->assertCount(2, $res->json('months'));

        // Bags row: monthly = [wk1 800, wk2 null], window Total 800.
        $bags = collect($res->json('rows'))->first(fn ($r) => $r['label'] === 'Bags');
        $this->assertNotNull($bags);
        $this->assertEquals(800.0, $bags['monthly'][0]);
        $this->assertNull($bags['monthly'][1]);
        $this->assertEquals(800.0, $bags['revenue']);
        // Share is a PERCENT of the range total (Bags 800 of 1000) = 80%.
        $this->assertEquals(80.0, $bags['share']);
    }

    public function test_month_only_sections_hide_themselves_under_a_custom_range(): void
    {
        // Kanwar, 2026-07-22 — month-only sections (prior-year lookback S12,
        // audience new-vs-existing spend S13, landing spend x sellers S17) have no
        // week-on-week view, so in custom-range mode they return hidden=true and
        // the card renders NOTHING instead of a confusing "No complete month
        // selected". In month mode they behave exactly as before.
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        foreach (['S12', 'S13', 'S17'] as $key) {
            $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/{$key}?period=custom&from=2026-06-01&to=2026-06-14&compare=last_year")
                ->assertOk()
                ->assertJsonPath('hidden', true);
        }

        // Month mode is unaffected — no hidden flag (the section renders normally,
        // here as an honest no_data since this bare brand has nothing synced).
        $monthRes = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S12?month=2026-06")->assertOk();
        $this->assertNull($monthRes->json('hidden'));
    }

    public function test_country_revenue_splits_into_week_on_week_matrix_under_a_custom_range(): void
    {
        // Kanwar, 2026-07-21 — S5 country revenue MoM under a custom range now
        // renders the SAME detailed matrix as month mode (compact per-week cells
        // + Total/Share/ROAS/ΔYoY/ΔMoM + "view full table" popup), just with weeks
        // as the periods — no more the stripped-down collapse table.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', self::TZ));
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $seedCountry = function (string $date, string $iso, float $sales) use ($brand): void {
            DB::table('commerce_daily_metrics')->insert([
                'brand_id' => $brand->id, 'date' => $date, 'dimension_type' => 'country',
                'dimension_key' => $iso, 'dimension_label' => $iso, 'orders' => 1,
                'total_sales' => $sales, 'refunds_amount' => 0, 'currency' => 'EUR',
                'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        };
        $seedCountry('2026-06-03', 'ES', 600);  // week 1 (Jun 1–7)
        $seedCountry('2026-06-10', 'ES', 400);  // week 2 (Jun 8–14)
        $seedCountry('2026-06-04', 'FR', 200);  // week 1
        $seedCountry('2026-06-20', 'ES', 900);  // outside the range

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S5?period=custom&from=2026-06-01&to=2026-06-14&compare=last_year")
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('range', true);

        $this->assertTrue($res->json('weekly'));
        $this->assertCount(2, $res->json('weekHeaders'));
        $this->assertSame(23, $res->json('weekHeaders.0.week'));

        // ES leads (1000 over the range): per-week monthly [600, 400], Total 1000.
        $es = collect($res->json('rows'))->first(fn ($r) => $r['iso2'] === 'ES');
        $this->assertNotNull($es);
        $this->assertEquals(600.0, $es['monthly'][0]);
        $this->assertEquals(400.0, $es['monthly'][1]);
        $this->assertEquals(1000.0, $es['revenue']);
        // Share of the range total (ES 1000 of 1200) = 83.3%.
        $this->assertEquals(83.3, $es['sharePct']);
        // ΔMoM is last-week-vs-prev-week: (400 − 600) / 600 = −33.3%.
        $this->assertEquals(-33.3, $es['deltaMoMPct']);

        CarbonImmutable::setTestNow();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
