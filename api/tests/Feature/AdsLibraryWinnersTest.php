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
 * Ads Library winners endpoint (Phase 1). Evidence gates at the AdAudit
 * boundaries (49/51/149/151), ROAS ranking, the 100 cap, niche filter, search,
 * and cross-brand RBAC (team_member sees only assigned brands). Rows seeded
 * date-only (guardrail 8); USD fx 1.0 so spend == spendUsd.
 */
final class AdsLibraryWinnersTest extends TestCase
{
    use RefreshDatabase;

    private function day(): string
    {
        return CarbonImmutable::now()->subDay()->toDateString();
    }

    /** @param array<string, mixed> $o */
    private function seedAd(int $brandId, string $adId, array $o = []): void
    {
        DB::table('ad_creative_daily')->insert(array_merge([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => $this->day(), 'ad_id' => $adId,
            'ad_name' => 'Ad ' . $adId, 'body_text' => null, 'media_type' => 'image',
            'spend' => 0, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 1, 'conversion_value' => 0,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ], $o));
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
    }

    public function test_evidence_floor_excludes_49_includes_51(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAd($brand->id, 'UNDER', ['spend' => 49, 'conversion_value' => 200]);
        $this->seedAd($brand->id, 'OVER', ['spend' => 51, 'conversion_value' => 200]);

        $this->admin();
        $res = $this->getJson('/api/ads-library/winners')->assertOk()->json();

        $ids = array_column($res['rows'], 'adId');
        $this->assertContains('OVER', $ids);
        $this->assertNotContains('UNDER', $ids);
        $this->assertSame(1, $res['total']);
    }

    public function test_confidence_early_below_150_solid_at_150(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAd($brand->id, 'EARLY', ['spend' => 149, 'conversion_value' => 300]);
        $this->seedAd($brand->id, 'SOLID', ['spend' => 151, 'conversion_value' => 300]);

        $this->admin();
        $rows = collect($this->getJson('/api/ads-library/winners')->assertOk()->json('rows'))->keyBy('adId');

        $this->assertSame('early', $rows['EARLY']['confidence']);
        $this->assertSame('solid', $rows['SOLID']['confidence']);
    }

    public function test_default_sort_is_roas_desc(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAd($brand->id, 'LOW', ['spend' => 100, 'conversion_value' => 100]);  // ROAS 1
        $this->seedAd($brand->id, 'HIGH', ['spend' => 100, 'conversion_value' => 300]); // ROAS 3

        $this->admin();
        $rows = $this->getJson('/api/ads-library/winners')->assertOk()->json('rows');

        $this->assertSame('HIGH', $rows[0]['adId']);
        $this->assertEqualsWithDelta(3.0, $rows[0]['roas'], 0.001);
    }

    public function test_niche_filter(): void
    {
        $foot = Brand::factory()->create(['base_currency' => 'USD', 'niche' => 'footwear']);
        $jewel = Brand::factory()->create(['base_currency' => 'USD', 'niche' => 'jewelry']);
        $this->seedAd($foot->id, 'F1', ['spend' => 200, 'conversion_value' => 400]);
        $this->seedAd($jewel->id, 'J1', ['spend' => 200, 'conversion_value' => 400]);

        $this->admin();
        $ids = array_column($this->getJson('/api/ads-library/winners?niche=footwear')->assertOk()->json('rows'), 'adId');
        $this->assertSame(['F1'], $ids);
    }

    public function test_cap_is_100_but_total_counts_all(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        for ($i = 0; $i < 101; $i++) {
            $this->seedAd($brand->id, 'A' . $i, ['spend' => 200, 'conversion_value' => 400]);
        }

        $this->admin();
        $res = $this->getJson('/api/ads-library/winners')->assertOk()->json();
        $this->assertCount(100, $res['rows']);
        $this->assertSame(101, $res['total']);
    }

    public function test_search_matches_body_text(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAd($brand->id, 'HOOK', ['spend' => 200, 'conversion_value' => 400, 'body_text' => 'unboxing the new drop']);
        $this->seedAd($brand->id, 'OTHER', ['spend' => 200, 'conversion_value' => 400, 'body_text' => 'winter sale ends soon']);

        $this->admin();
        $ids = array_column($this->getJson('/api/ads-library/winners?search=unboxing')->assertOk()->json('rows'), 'adId');
        $this->assertSame(['HOOK'], $ids);
    }

    public function test_team_member_sees_only_assigned_brands(): void
    {
        $mine = Brand::factory()->create(['base_currency' => 'USD']);
        $other = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAd($mine->id, 'MINE', ['spend' => 200, 'conversion_value' => 400]);
        $this->seedAd($other->id, 'THEIRS', ['spend' => 200, 'conversion_value' => 400]);

        $tm = User::factory()->create(['role' => 'team_member']);
        $mine->users()->attach($tm->id);
        Sanctum::actingAs($tm);

        $ids = array_column($this->getJson('/api/ads-library/winners')->assertOk()->json('rows'), 'adId');
        $this->assertContains('MINE', $ids);
        $this->assertNotContains('THEIRS', $ids);

        // Admin sees both.
        $this->admin();
        $adminIds = array_column($this->getJson('/api/ads-library/winners')->assertOk()->json('rows'), 'adId');
        $this->assertContains('MINE', $adminIds);
        $this->assertContains('THEIRS', $adminIds);
    }
}
