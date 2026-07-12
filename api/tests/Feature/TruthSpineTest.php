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
 * GO-1.4 — triangulated truth. MER (store revenue ÷ total ad spend) is the spine;
 * each platform's OWN reported ROAS sits beside it carrying its bias annotation.
 *
 * The invariant under test: platform-reported revenue is NEVER summed into a total.
 * Two platforms routinely claim the same order, so a "total attributed revenue" is a
 * fiction — and shipping that fiction is how the incumbents lost senior buyers.
 */
final class TruthSpineTest extends TestCase
{
    use RefreshDatabase;

    private function connect(int $brandId, string $platform): void
    {
        DB::table('platform_connections')->insert([
            'brand_id' => $brandId, 'platform' => $platform, 'external_id' => "acct_{$platform}",
            'credentials' => '{}', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $cols */
    private function daily(int $brandId, string $platform, string $date, array $cols): void
    {
        DB::table('daily_metrics')->insert(array_merge([
            'brand_id' => $brandId, 'platform' => $platform, 'date' => $date,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ], $cols));
    }

    /** @return array<string, mixed> */
    private function truth(Brand $brand): array
    {
        return $this->getJson("/api/brands/{$brand->slug}/truth?period=last30")->assertOk()->json();
    }

    public function test_mer_is_store_revenue_over_total_spend_and_platforms_are_annotated(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        $day   = CarbonImmutable::now('UTC')->subDays(2)->toDateString();

        $this->connect($brand->id, 'shopify');
        $this->connect($brand->id, 'meta');
        $this->connect($brand->id, 'google');

        // Store took 1000. Meta spent 200 and CLAIMS 800. Google spent 300 and CLAIMS 600.
        $this->daily($brand->id, 'shopify', $day, ['total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 10]);
        $this->daily($brand->id, 'meta',    $day, ['spend' => 200, 'conversion_value' => 800]);
        $this->daily($brand->id, 'google',  $day, ['spend' => 300, 'conversion_value' => 600]);

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $t = $this->truth($brand);

        // MER = 1000 / (200 + 300) = 2.0×  — the spine, from money the store actually took.
        $this->assertEqualsWithDelta(2.0, (float) $t['mer'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $t['storeRevenue'], 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $t['totalSpend'], 0.001);
        $this->assertStringContainsString('store truth', $t['merLabel']);

        $byPlatform = collect($t['platforms'])->keyBy('platform');

        // Each platform reports its OWN ROAS, with its bias annotation attached.
        $this->assertEqualsWithDelta(4.0, (float) $byPlatform['meta']['reportedRoas'], 0.001);   // 800/200
        $this->assertEqualsWithDelta(2.0, (float) $byPlatform['google']['reportedRoas'], 0.001); // 600/300
        $this->assertNotEmpty($byPlatform['meta']['annotation']);
        $this->assertNotEmpty($byPlatform['google']['annotation']);
        $this->assertStringContainsString('Advantage+', $byPlatform['meta']['annotation']);
        $this->assertStringContainsString('unverified', $byPlatform['google']['annotation']);
        $this->assertStringContainsString('unverified', strtolower($byPlatform['meta']['label']));

        // THE INVARIANT: platform-reported revenue (800 + 600 = 1400) is a LIST, never
        // a total, and never replaces the 1000 the store actually took.
        $this->assertArrayNotHasKey('totalAttributedRevenue', $t);
        $this->assertArrayNotHasKey('totalReportedRevenue', $t);
        $this->assertEqualsWithDelta(1000.0, (float) $t['storeRevenue'], 0.001);
        $this->assertNotEmpty($t['divergenceNote']);
    }

    public function test_no_spend_means_null_roas_never_zero(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        $day   = CarbonImmutable::now('UTC')->subDays(2)->toDateString();

        $this->connect($brand->id, 'shopify');
        $this->connect($brand->id, 'meta');
        $this->daily($brand->id, 'shopify', $day, ['total_sales' => 500, 'refunds_amount' => 0]);
        // Meta connected but no spend rows at all.

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $t = $this->truth($brand);

        $meta = collect($t['platforms'])->firstWhere('platform', 'meta');
        $this->assertNull($meta['reportedRoas']);   // missing ≠ 0
        $this->assertNull($t['mer']);               // no spend → no MER, never 0
    }

    public function test_unconnected_platforms_are_absent_not_zero_rows(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        $this->connect($brand->id, 'shopify');
        $this->connect($brand->id, 'meta');

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $t = $this->truth($brand);

        $platforms = collect($t['platforms'])->pluck('platform')->all();
        $this->assertContains('meta', $platforms);
        $this->assertNotContains('google', $platforms);  // not connected → absent
        $this->assertNotContains('tiktok', $platforms);
    }
}
