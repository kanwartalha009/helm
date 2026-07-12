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
 * GO-1.2 — product costs → contribution margin. Proves the precedence chain
 * (manual > Shopify > brand gross-margin % > UNKNOWN), that an unknown cost renders
 * null and NEVER a fake 0/100% margin, and that manual costs are effective-dated so a
 * later price change cannot rewrite an earlier window's margin.
 *
 * Fixture: 10 units of "Shoe" sold for 1000, with 200 of mapped ad spend.
 *   contribution = revenue − COGS − mapped ad spend
 */
final class ProductCostsTest extends TestCase
{
    use RefreshDatabase;

    private function day(): string
    {
        return CarbonImmutable::now()->subDays(2)->toDateString();
    }

    private function seedSales(int $brandId): void
    {
        DB::table('commerce_daily_metrics')->insert([
            'brand_id' => $brandId, 'date' => $this->day(),
            'dimension_type' => 'product', 'dimension_key' => 'Shoe', 'dimension_label' => 'Shoe',
            'orders' => 5, 'units' => 10, 'total_sales' => 1000, 'refunds_amount' => 0,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
        DB::table('ad_product_daily')->insert([
            'brand_id' => $brandId, 'date' => $this->day(), 'product_key' => 'shoe',
            'spend' => 200, 'ads_count' => 1, 'currency' => 'USD', 'fx_rate_to_usd' => 1.0,
            'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    /** Catalog row links title "Shoe" → handle "shoe" (the ad + cost key). */
    private function seedCatalog(int $brandId, ?float $unitCost): void
    {
        DB::table('product_catalog')->insert([
            'brand_id' => $brandId, 'handle' => 'shoe', 'title' => 'Shoe',
            'variant_count' => 1, 'total_inventory' => 50,
            'unit_cost' => $unitCost, 'unit_cost_currency' => $unitCost !== null ? 'USD' : null,
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> the "Shoe" row */
    private function row(Brand $brand): array
    {
        $res = $this->getJson("/api/brands/{$brand->slug}/products?period=last30")->assertOk()->json();

        return collect($res['rows'])->firstWhere('key', 'Shoe');
    }

    public function test_shopify_unit_cost_drives_contribution_margin(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => null]);
        $this->seedSales($brand->id);
        $this->seedCatalog($brand->id, 30.0);   // 10 units × 30 = 300 COGS
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $row = $this->row($brand);
        $this->assertSame('shopify', $row['costSource']);
        $this->assertEqualsWithDelta(30.0, $row['unitCost'], 0.001);
        $this->assertEqualsWithDelta(300.0, $row['cogs'], 0.001);
        // 1000 − 300 − 200 = 500 (50%)
        $this->assertEqualsWithDelta(500.0, $row['contributionMargin'], 0.001);
        $this->assertEqualsWithDelta(50.0, $row['contributionMarginPct'], 0.001);
    }

    public function test_manual_cost_overrides_shopify(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => null]);
        $this->seedSales($brand->id);
        $this->seedCatalog($brand->id, 30.0);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        // A manual cost in force before the window → wins over Shopify's 30.
        $this->putJson("/api/brands/{$brand->slug}/product-costs", [
            'product_key' => 'shoe', 'unit_cost' => 40, 'effective_from' => CarbonImmutable::now()->subDays(10)->toDateString(),
        ])->assertCreated();

        $row = $this->row($brand);
        $this->assertSame('manual', $row['costSource']);
        $this->assertEqualsWithDelta(400.0, $row['cogs'], 0.001);              // 10 × 40
        $this->assertEqualsWithDelta(400.0, $row['contributionMargin'], 0.001); // 1000 − 400 − 200
    }

    public function test_manual_cost_is_effective_dated_and_does_not_rewrite_the_past(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => null]);
        $this->seedSales($brand->id);
        $this->seedCatalog($brand->id, 30.0);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        // A price rise dated TOMORROW must not apply to a window that ends yesterday.
        $this->putJson("/api/brands/{$brand->slug}/product-costs", [
            'product_key' => 'shoe', 'unit_cost' => 99, 'effective_from' => CarbonImmutable::now()->addDay()->toDateString(),
        ])->assertCreated();

        $row = $this->row($brand);
        $this->assertSame('shopify', $row['costSource']);                 // future cost ignored
        $this->assertEqualsWithDelta(300.0, $row['cogs'], 0.001);         // still 10 × 30
    }

    public function test_brand_margin_is_the_fallback_when_no_unit_cost_exists(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => 60]);
        $this->seedSales($brand->id);
        $this->seedCatalog($brand->id, null);   // Shopify exposes no cost
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $row = $this->row($brand);
        $this->assertSame('brand_margin', $row['costSource']);
        $this->assertNull($row['unitCost']);
        $this->assertEqualsWithDelta(400.0, $row['cogs'], 0.001);               // 1000 × (1 − 0.60)
        $this->assertEqualsWithDelta(400.0, $row['contributionMargin'], 0.001); // 1000 − 400 − 200
    }

    public function test_unknown_cost_is_null_never_zero(): void
    {
        // No Shopify cost, no manual cost, no brand margin → margin is UNKNOWN.
        // It must render "—" (null), never a 0 COGS / 100% margin.
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => null]);
        $this->seedSales($brand->id);
        $this->seedCatalog($brand->id, null);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $res = $this->getJson("/api/brands/{$brand->slug}/products?period=last30")->assertOk()->json();
        $row = collect($res['rows'])->firstWhere('key', 'Shoe');

        $this->assertNull($row['cogs']);
        $this->assertNull($row['contributionMargin']);
        $this->assertNull($row['contributionMarginPct']);
        $this->assertNull($row['costSource']);
        $this->assertFalse($res['costs']['hasBasis']);   // drives the "set costs" hint
    }

    public function test_setting_a_cost_is_admin_manager_only(): void
    {
        // Attached team_member CAN see the brand (so this is a 403 on the ACTION,
        // not a 404 on visibility — the DataCoverageTest attach-then-403 pattern).
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $tm    = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);

        Sanctum::actingAs($tm);
        $this->putJson("/api/brands/{$brand->slug}/product-costs", ['product_key' => 'shoe', 'unit_cost' => 10])
            ->assertForbidden();
    }
}
