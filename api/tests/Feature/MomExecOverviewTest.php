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

        // Columns: Metric + one per week + Total = 4.
        $columns = $res->json('rangeCollapse.columns');
        $this->assertCount(4, $columns);
        $this->assertSame('Metric', $columns[0]);
        $this->assertSame('Total', $columns[3]);

        // Revenue row: [label, wk1=700, wk2=0, total=700].
        $revenueRow = collect($res->json('rangeCollapse.rows'))->first(fn ($r) => $r[0]['v'] === 'Revenue');
        $this->assertNotNull($revenueRow);
        $this->assertCount(4, $revenueRow);
        $this->assertEquals(700.0, $revenueRow[1]['v']);
        $this->assertEquals(0.0, $revenueRow[2]['v']);
        $this->assertEquals(700.0, $revenueRow[3]['v']); // range total, not summed last-year

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

        // Segment + 2 weeks + Total = 4 columns.
        $this->assertCount(4, $res->json('rangeCollapse.columns'));

        // Bags row: [label, wk1=800, wk2=null, total=800].
        $bags = collect($res->json('rangeCollapse.rows'))->first(fn ($r) => $r[0]['v'] === 'Bags');
        $this->assertNotNull($bags);
        $this->assertEquals(800.0, $bags[1]['v']);
        $this->assertNull($bags[2]['v']);
        $this->assertEquals(800.0, $bags[3]['v']);

        // The Total footer sums the segments: wk1 = 1000 (800+200), total 1000.
        $footer = $res->json('rangeCollapse.footer');
        $this->assertNotNull($footer);
        $this->assertSame('Total', $footer[0]['v']);
        $this->assertEquals(1000.0, $footer[1]['v']);
        $this->assertEquals(1000.0, $footer[3]['v']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
