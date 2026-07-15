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
 * M2/M3 final backend slice (monthly-report-v2-mom.md): S1 (financial
 * matrix, built WITHOUT the customer_type-probe columns per the spec's own
 * fallback rule), S7 (best categories + stock chip), S8 (best sellers +
 * stock flag), S17 (landing spend x best sellers, unblocked via the
 * product_catalog handle<->title bridge found during the M2-continuation
 * pass).
 */
class MomM2FinalSectionsTest extends TestCase
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

    private function seedShopifyDaily(int $brandId, string $date, float $totalSales, int $orders): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $brandId, 'platform' => 'shopify', 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'total_sales' => $totalSales, 'refunds_amount' => 0, 'orders' => $orders,
        ]);
    }

    private function seedAdSpend(int $brandId, string $platform, string $date, float $spend): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $brandId, 'platform' => $platform, 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'spend' => $spend,
        ]);
    }

    private function seedCommerce(int $brandId, string $date, string $dimensionType, string $key, float $revenue): void
    {
        DB::table('commerce_daily_metrics')->insert([
            'brand_id' => $brandId, 'date' => $date, 'dimension_type' => $dimensionType,
            'dimension_key' => $key, 'dimension_label' => $key,
            'orders' => 1, 'total_sales' => $revenue, 'refunds_amount' => 0,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function seedProductCatalog(int $brandId, string $handle, string $title, string $productType, int $stock): void
    {
        DB::table('product_catalog')->insert([
            'brand_id' => $brandId, 'handle' => $handle, 'title' => $title, 'product_type' => $productType,
            'variant_count' => 1, 'total_inventory' => $stock, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_shell_reports_s1_s7_s8_s17_ready(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedShopifyDaily($brand->id, $this->monthStart()->addDays(2)->toDateString(), 100, 1);

        $sections = collect($this->getJson("/api/brands/{$brand->slug}/reports/mom")->assertOk()->json('sections'))->keyBy('key');
        foreach (['S1', 'S7', 'S8', 'S17'] as $k) {
            $this->assertTrue($sections[$k]['ready'], "{$k} should be ready");
        }
        $this->assertFalse($sections['S16']['ready'], 'S16 should still not be ready');
    }

    public function test_s1_financial_matrix_computes_mom_yoy_and_omits_customer_columns(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $prevMonth = $month->subMonth();
        $lastYear  = $month->subYear();

        $this->seedShopifyDaily($brand->id, $month->toDateString(), 1000, 10);
        $this->seedAdSpend($brand->id, 'meta', $month->toDateString(), 400);
        $this->seedAdSpend($brand->id, 'google', $month->toDateString(), 100);

        $this->seedShopifyDaily($brand->id, $prevMonth->toDateString(), 800, 8);
        $this->seedAdSpend($brand->id, 'meta', $prevMonth->toDateString(), 400);

        $this->seedShopifyDaily($brand->id, $lastYear->toDateString(), 500, 5);
        $this->seedAdSpend($brand->id, 'meta', $lastYear->toDateString(), 200);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S1?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $currentRows = collect($res->json('currentYearRows'))->keyBy('month');
        $reportRow = $currentRows[$month->format('Y-m')];
        $this->assertEquals(10, $reportRow['orders']);
        $this->assertEquals(1000.0, $reportRow['revenue']);
        $this->assertEquals(500.0, $reportRow['spend']);
        $this->assertEquals(20.0, $reportRow['googleSharePct']); // 100/500
        $this->assertEquals(2.0, $reportRow['roas']);
        $this->assertEqualsWithDelta(25.0, $reportRow['deltaRevenuePct'], 0.1); // vs 800
        $this->assertEquals('up', $reportRow['revenueFlag']);

        $this->assertEqualsWithDelta(100.0, $res->json('summary.revenueYoYPct'), 0.1); // 1000 vs 500

        $this->assertArrayHasKey('customerColumns', $res->json('unavailable'));
    }

    public function test_s7_categories_and_s8_best_sellers_join_stock_from_product_catalog(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(2)->toDateString();

        $this->seedCommerce($brand->id, $date, 'category', 'Hoodies', 1000);
        $this->seedProductCatalog($brand->id, 'red-hoodie', 'Red Hoodie', 'Hoodies', 3); // low stock

        $this->seedCommerce($brand->id, $date, 'product', 'Red Hoodie', 900);
        $this->seedCommerce($brand->id, $date, 'product', 'Blue Cap', 100);
        $this->seedProductCatalog($brand->id, 'blue-cap', 'Blue Cap', 'Caps', 50);

        $s7 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S7?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $hoodieRow = collect($s7->json('rows'))->firstWhere('label', 'Hoodies');
        $this->assertEquals(3, $hoodieRow['stock']);
        $this->assertTrue($hoodieRow['lowStock']);

        $s8 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S8?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');
        $redHoodieRow = collect($s8->json('rows'))->firstWhere('label', 'Red Hoodie');
        $this->assertEquals(3, $redHoodieRow['stock']);
        $this->assertSame('red', $redHoodieRow['stockFlag']);
        $blueCapRow = collect($s8->json('rows'))->firstWhere('label', 'Blue Cap');
        $this->assertNull($blueCapRow['stockFlag']); // 50 units, not low
    }

    public function test_s17_joins_ad_spend_by_handle_to_commerce_revenue_by_title_and_flags_mismatch(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(2)->toDateString();

        $this->seedProductCatalog($brand->id, 'blue-cap', 'Blue Cap', 'Caps', 50);
        $this->seedProductCatalog($brand->id, 'red-hoodie', 'Red Hoodie', 'Hoodies', 20);

        // Spending heavily on Blue Cap, but Red Hoodie is the real best seller -> mismatch.
        DB::table('ad_product_daily')->insert([
            'brand_id' => $brand->id, 'platform' => 'meta', 'date' => $date, 'product_key' => 'blue-cap',
            'spend' => 900, 'ads_count' => 2, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
        DB::table('ad_product_daily')->insert([
            'brand_id' => $brand->id, 'platform' => 'meta', 'date' => $date, 'product_key' => 'red-hoodie',
            'spend' => 100, 'ads_count' => 1, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
        $this->seedCommerce($brand->id, $date, 'product', 'Blue Cap', 200);
        $this->seedCommerce($brand->id, $date, 'product', 'Red Hoodie', 2000);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S17?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $blueCapRow = collect($res->json('rows'))->firstWhere('handle', 'blue-cap');
        $this->assertEquals('Blue Cap', $blueCapRow['title']);
        $this->assertEquals(200.0, $blueCapRow['revenue']);
        $this->assertEquals(900.0, $blueCapRow['spend']);

        $this->assertNotNull($res->json('mismatch'));
        $this->assertSame('Blue Cap', $res->json('mismatch.spendingOn'));
        $this->assertSame('Red Hoodie', $res->json('mismatch.bestSeller'));
    }
}
