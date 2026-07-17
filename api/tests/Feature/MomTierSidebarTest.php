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
 * M5 addendum (Kanwar, 2026-07-15 — "tier system move to side bar... show
 * list of countries against the brand to group them"): CountryTierController
 * ::availableCountries(), the real-country feed the tier sidebar reads
 * instead of a free-text ISO-2 field. Reuses the SAME CountryRevenueSpend
 * join S5/S6 already exercise (MomM2ContinuedTest) — this test only covers
 * the NEW endpoint's own contract: real revenue rows, the union-in of
 * already-assigned-but-revenue-less countries, and RBAC (read is brand-
 * visible, same as show()).
 */
class MomTierSidebarTest extends TestCase
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

    public function test_available_countries_returns_real_revenue_rows_with_current_tier(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $date  = $this->monthStart()->addDays(3)->toDateString();

        $this->seedCommerceCountry($brand->id, $date, 'Spain', 1000, 10);
        $this->seedMetaCountrySpend($brand->id, $date, 'ES', 250);

        DB::table('country_tiers')->insert([
            'brand_id' => $brand->id, 'tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111',
            'countries' => json_encode(['ES']), 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/country-tiers/available-countries")->assertOk();
        $rows = collect($res->json('countries'))->keyBy('iso2');

        $this->assertEquals(1000.0, $rows['ES']['revenue']);
        $this->assertEquals(250.0, $rows['ES']['spend']);
        $this->assertSame('T1', $rows['ES']['tierKey']);
        $this->assertSame(6, $res->json('windowMonths'));
    }

    public function test_available_countries_unions_in_an_assigned_country_with_no_recent_revenue(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        // FR is assigned to a tier but has NO commerce/spend rows at all —
        // must still appear (honest null revenue), not silently vanish.
        DB::table('country_tiers')->insert([
            'brand_id' => $brand->id, 'tier_key' => 'T2', 'label' => 'Tier 2', 'color' => '#222222',
            'countries' => json_encode(['FR']), 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/country-tiers/available-countries")->assertOk();
        $rows = collect($res->json('countries'))->keyBy('iso2');

        $this->assertArrayHasKey('FR', $rows);
        $this->assertNull($rows['FR']['revenue']);
        $this->assertSame('T2', $rows['FR']['tierKey']);
    }

    public function test_available_countries_is_brand_visible_read_not_admin_gated(): void
    {
        $brand = $this->makeBrand();
        // team_member (not admin/manager) can still READ — same split as
        // show()'s own RBAC (MomM1Test::test_country_tiers_and_report_layouts_rbac).
        $tm = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);

        $this->getJson("/api/brands/{$brand->slug}/country-tiers/available-countries")->assertOk();
    }

    public function test_master_admin_saves_tiers_with_the_camelcase_payload_the_drawer_sends(): void
    {
        // Regression (Kanwar, 2026-07-16 — "why can't I save tiers, I'm master
        // admin"): the tier drawer PUTs `tierKey` (camelCase, matching the read
        // endpoint's own shape), but validation required snake_case `tier_key`,
        // so every save 422'd and surfaced as a generic "Admins and managers
        // only" toast. The endpoint must accept the shape it hands back.
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $this->putJson("/api/brands/{$brand->slug}/country-tiers", [
            'tiers' => [
                ['tierKey' => 'DE', 'label' => 'Germany', 'color' => '#7c3aed', 'countries' => ['DE']],
                ['tierKey' => 'US', 'label' => 'United States', 'color' => '#65a30d', 'countries' => ['US']],
            ],
        ])->assertStatus(201);

        // Persisted and resolvable.
        $res = $this->getJson("/api/brands/{$brand->slug}/country-tiers")->assertOk();
        $this->assertSame('DE', $res->json('resolved.DE.tierKey'));
        $this->assertSame('US', $res->json('resolved.US.tierKey'));
    }
}
