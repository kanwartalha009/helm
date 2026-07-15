<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M2 continuation (monthly-report-v2-mom.md §M2): the money + market sections
 * built after the M2/M3 first slices — S2 (sales evolution), S3 (new vs
 * returning, honest shell), S4 (revenue by tier), S5 (country revenue +
 * TOP/CHECK/ALARM status), S6 (ROAS by country), S9 (sessions & CR), S10/S11
 * (funnel by country/landing), S12 (prior-year next-month lookback).
 *
 * S1 (financial matrix) is NOT covered here — deliberately not built this
 * pass (see MomSectionRegistry's docblock).
 */
class MomM2ContinuedTest extends TestCase
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

    private function seedShopifyDaily(int $brandId, string $date, float $totalSales, float $refunds = 0): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $brandId, 'platform' => 'shopify', 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'total_sales' => $totalSales, 'refunds_amount' => $refunds,
        ]);
    }

    private function seedCommerceCountry(int $brandId, string $date, string $countryName, float $totalSales, int $orders = 1): void
    {
        DB::table('commerce_daily_metrics')->insert([
            'brand_id' => $brandId, 'date' => $date, 'dimension_type' => 'country',
            'dimension_key' => $countryName, 'dimension_label' => $countryName,
            'orders' => $orders, 'total_sales' => $totalSales, 'refunds_amount' => 0,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function seedMetaCountrySpend(int $brandId, string $date, string $iso2, float $spend): void
    {
        DB::table('meta_breakdown_daily')->insert([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => $date,
            'breakdown_type' => 'country', 'segment_key' => $iso2, 'segment_label' => $iso2,
            'spend' => $spend, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function seedFunnel(int $brandId, string $date, string $dimension, string $key, array $cols): void
    {
        DB::table('shopify_funnel_daily')->insert(array_merge([
            'brand_id' => $brandId, 'date' => $date, 'dimension' => $dimension,
            'segment_key' => $key, 'segment_label' => $key, 'is_complete' => true, 'pulled_at' => now(),
        ], $cols));
    }

    public function test_shell_reports_the_nine_new_sections_ready(): void
    {
        // CORRECTED (M5 S1/HeatTable pass, 2026-07-15): this test originally
        // also asserted S1 was NOT ready, a snapshot of the registry at the
        // time this test was written — S1 has been built (and colour-coded)
        // for several passes now. See MomM2FinalSectionsTest for S1's own
        // build-logic coverage.
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedShopifyDaily($brand->id, $this->monthStart()->addDays(2)->toDateString(), 100);

        $sections = collect($this->getJson("/api/brands/{$brand->slug}/reports/mom")->assertOk()->json('sections'))->keyBy('key');

        foreach (['S2', 'S3', 'S4', 'S5', 'S6', 'S9', 'S10', 'S11', 'S12'] as $k) {
            $this->assertTrue($sections[$k]['ready'], "{$k} should be ready");
        }
    }

    public function test_s2_sales_evolution_returns_a_daily_series_summing_to_the_month_total(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $this->seedShopifyDaily($brand->id, $month->addDays(1)->toDateString(), 100);
        $this->seedShopifyDaily($brand->id, $month->addDays(5)->toDateString(), 200, 10); // 210 D-005

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S2?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEquals(310.0, $res->json('total'));
        $this->assertCount(2, $res->json('series'));
    }

    public function test_s3_new_vs_returning_is_an_honest_needs_source_shell(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S3")
            ->assertOk()->assertJsonPath('status', 'needs_source');
    }

    public function test_s4_s5_s6_country_and_tier_join_reconciles_and_flags_alarm(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        // Spain: 1000 revenue, 1000 spend -> ROAS 1.0 (below the 1.5 alarm floor).
        $this->seedCommerceCountry($brand->id, $date, 'Spain', 1000, 10);
        $this->seedMetaCountrySpend($brand->id, $date, 'ES', 1000);
        // Germany: 3000 revenue, 500 spend -> ROAS 6.0 (comfortably top).
        $this->seedCommerceCountry($brand->id, $date, 'Germany', 3000, 20);
        $this->seedMetaCountrySpend($brand->id, $date, 'DE', 500);

        // CORRECTED (M5 S1/HeatTable pass, 2026-07-15): a brand-scoped override
        // row, not an agency-default (brand_id null) row — the seed migration
        // (2026_07_14_000003_seed_agency_default_country_tiers) already inserts
        // an agency-default T1, so inserting a second brand_id=null/tier_key=T1
        // row here was a unique-constraint collision (pre-existing, unrelated to
        // this pass, just found while re-running the suite). A brand override
        // exercises the exact same resolution path this section reads.
        DB::table('country_tiers')->insert([
            'brand_id' => $brand->id, 'tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111',
            'countries' => json_encode(['ES', 'DE']), 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // S5: country revenue + status.
        $s5 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S5?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $rows = collect($s5->json('rows'))->keyBy('iso2');
        $this->assertEquals(1000.0, $rows['ES']['revenue']);
        $this->assertEquals('ALARM', $rows['ES']['status']);
        $this->assertEquals('TOP', $rows['DE']['status']);
        $this->assertNotNull($s5->json('suggestedTitle'));

        // S6: ROAS by country, sorted by ROAS desc.
        $s6 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S6?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame('DE', $s6->json('rows.0.iso2'));
        $this->assertEquals(6.0, $s6->json('rows.0.roas'));

        // S4: revenue by tier — both countries fold into T1.
        $s4 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S4?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertEquals(4000.0, $s4->json('rows.0.revenue')); // 1000 + 3000
        $this->assertSame('T1', $s4->json('rows.0.tierKey'));
    }

    public function test_s9_sessions_and_s10_s11_funnel_sections(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        $this->seedFunnel($brand->id, $date, 'country', 'ES', ['sessions' => 1000, 'cart_additions' => 100, 'reached_checkout' => 50, 'completed_checkout' => 20]);
        $this->seedFunnel($brand->id, $date, 'landing', '/products/foo', ['sessions' => 500, 'cart_additions' => 60, 'reached_checkout' => 30, 'completed_checkout' => 15]);
        $this->seedFunnel($brand->id, $date, 'landing', '/products/bar', ['sessions' => 200, 'cart_additions' => 0, 'reached_checkout' => 0, 'completed_checkout' => 0]);

        $s9 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S9?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertEquals(1000, $s9->json('sessions.value'));
        $this->assertEquals(2.0, $s9->json('cvr.value')); // 20/1000

        $s10 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S10?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->assertEquals('ES', $s10->json('rows.0.key'));

        $s11 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S11?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        // The zero-purchase landing page ('/products/bar') is dropped, not padded in.
        $this->assertCount(1, $s11->json('rows'));
        $this->assertSame('/products/foo', $s11->json('rows.0.key'));
    }

    public function test_s12_prior_year_next_month_lookback_reads_the_correct_fixed_window(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();

        // Report month is $month; lookback = ($month + 1 month) - 1 year.
        $lookback = $month->addMonth()->subYear();
        $this->seedShopifyDaily($brand->id, $lookback->addDays(4)->toDateString(), 777);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S12?month={$this->monthStart()->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame($lookback->startOfMonth()->format('Y-m'), $res->json('lookbackMonth'));
        $this->assertEquals(777.0, $res->json('total'));
    }
}
