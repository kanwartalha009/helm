<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
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

        // The trailing 6-month TREND series backs the new/returning graph: the
        // report month carries the same modeled split; prior months with no
        // data are null (line breaks honestly, never a fake 0).
        $series = collect($res->json('customerSalesSeries'));
        $this->assertCount(6, $series);
        $reportMonth = $series->firstWhere('month', $this->monthStart()->format('Y-m'));
        $this->assertEquals(600.0, $reportMonth['new']);
        $this->assertEquals(400.0, $reportMonth['returning']);
        $this->assertNull($series->first()['new']); // 5 months back — no data seeded
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
