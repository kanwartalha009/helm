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
 * Product-level ROAS on the products page (spec §4 Phase 5): mapped ad spend +
 * ROAS per product (bridged commerce title → catalog handle → ad_product_daily),
 * the losing_on_ads flag on real below-breakeven spend, and the honest footer —
 * mapped % excludes the __other/__collection buckets, so unmapped spend never
 * inflates the numerator.
 */
final class ProductRoasTest extends TestCase
{
    use RefreshDatabase;

    private function day(): string
    {
        return CarbonImmutable::now()->subDay()->toDateString();
    }

    private function seedCommerce(int $brandId, string $title, float $revenue): void
    {
        DB::table('commerce_daily_metrics')->insert([
            'brand_id' => $brandId, 'date' => $this->day(), 'dimension_type' => 'product',
            'dimension_key' => $title, 'dimension_label' => $title, 'orders' => 3, 'units' => 5,
            'net_sales' => $revenue, 'total_sales' => $revenue, 'refunds_amount' => 0,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function seedCatalog(int $brandId, string $handle, string $title): void
    {
        DB::table('product_catalog')->insert([
            'brand_id' => $brandId, 'handle' => $handle, 'title' => $title,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedAdProduct(int $brandId, string $platform, string $key, float $spend): void
    {
        DB::table('ad_product_daily')->insert([
            'brand_id' => $brandId, 'platform' => $platform, 'date' => $this->day(), 'product_key' => $key,
            'spend' => $spend, 'ads_count' => 1, 'currency' => 'USD', 'fx_rate_to_usd' => 1.0,
            'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    public function test_product_roas_flag_and_footer_math(): void
    {
        // margin 50% → breakeven 2.0×.
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => 50]);
        $this->seedCommerce($brand->id, 'Blue Tee', 300);
        $this->seedCommerce($brand->id, 'Red Cap', 100);
        $this->seedCatalog($brand->id, 'blue-tee', 'Blue Tee');
        $this->seedCatalog($brand->id, 'red-cap', 'Red Cap');

        // Mapped: blue-tee $200 (ROAS 1.5 < 2.0 breakeven, ≥$100 → losing_on_ads),
        // red-cap $20 (ROAS 5.0, under $100 → no flag). Unmapped: __other $80.
        $this->seedAdProduct($brand->id, 'meta', 'blue-tee', 200);
        $this->seedAdProduct($brand->id, 'meta', 'red-cap', 20);
        $this->seedAdProduct($brand->id, 'meta', '__other', 80);

        // Total Meta spend for the window (mapped % denominator) = $300.
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'meta', 'date' => $this->day(),
            'spend' => 300, 'conversion_value' => 400, 'conversions' => 6,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->getJson("/api/brands/{$brand->slug}/products?period=last30")->assertOk()->json();

        $rows = collect($res['rows'])->keyBy('key');

        $blue = $rows['Blue Tee'];
        $this->assertEqualsWithDelta(200.0, $blue['adSpend'], 0.001);
        $this->assertEqualsWithDelta(1.5, $blue['roas'], 0.001);
        $this->assertContains('losing_on_ads', array_column($blue['flags'], 'key'));

        $red = $rows['Red Cap'];
        $this->assertEqualsWithDelta(20.0, $red['adSpend'], 0.001);
        $this->assertEqualsWithDelta(5.0, $red['roas'], 0.001);
        $this->assertNotContains('losing_on_ads', array_column($red['flags'], 'key'));

        // Footer: mapped = 200+20 = 220 (excludes __other 80); total = 300 → 73.3%.
        $this->assertEqualsWithDelta(220.0, $res['adSpend']['mappedSpend'], 0.01);
        $this->assertEqualsWithDelta(300.0, $res['adSpend']['totalSpend'], 0.01);
        $this->assertEqualsWithDelta(73.3, $res['adSpend']['mappedPct'], 0.1);
    }

    public function test_unmapped_product_shows_no_roas(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedCommerce($brand->id, 'Orphan Item', 150);
        // No catalog handle, no ad_product_daily row → ad spend / ROAS stay null.

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->getJson("/api/brands/{$brand->slug}/products?period=last30")->assertOk()->json();

        $row = collect($res['rows'])->firstWhere('key', 'Orphan Item');
        $this->assertNull($row['adSpend']);
        $this->assertNull($row['roas']);
        $this->assertNull($res['adSpend']['mappedPct'], 'no ad spend at all → mapped % is —, not 0');
    }
}
