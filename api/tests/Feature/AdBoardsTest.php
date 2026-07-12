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
 * Ads Library Phase 4 — boards, items, tag benchmarks, briefs. Benchmarks are
 * evidence-gated (≥3 internal creatives clearing $50) and read the OWN account
 * only (D-022: no cross-tenant pooling).
 */
final class AdBoardsTest extends TestCase
{
    use RefreshDatabase;

    private function seedCreative(int $brandId, string $adId, float $spend, float $rev): void
    {
        DB::table('ad_creative_daily')->insert([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => CarbonImmutable::now()->subDay()->toDateString(),
            'ad_id' => $adId, 'ad_name' => 'Ad ' . $adId, 'media_type' => 'video',
            'spend' => $spend, 'impressions' => 1000, 'clicks' => 20, 'conversions' => 3, 'conversion_value' => $rev,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    public function test_board_item_and_tag_benchmarks(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $boardId = $this->postJson('/api/ads-library/boards', ['name' => 'Q3 hooks', 'brand_id' => $brand->id, 'niche' => 'footwear'])
            ->assertCreated()->json('id');

        // 3 internal ads clearing $50, all tagged the same hook.
        foreach (['A1' => 200.0, 'A2' => 150.0, 'A3' => 100.0] as $ad => $rev) {
            $this->seedCreative($brand->id, $ad, 100.0, $rev);
            $this->postJson("/api/ads-library/boards/{$boardId}/items", [
                'source' => 'internal', 'ref_id' => $ad, 'tags' => ['hook:problem-callout'],
            ])->assertCreated();
        }

        // Idempotent add (same source+ref) does not duplicate.
        $this->postJson("/api/ads-library/boards/{$boardId}/items", ['source' => 'internal', 'ref_id' => 'A1', 'tags' => ['hook:problem-callout']])->assertCreated();
        $this->assertSame(3, DB::table('ad_board_items')->where('board_id', $boardId)->count());

        $show = $this->getJson("/api/ads-library/boards/{$boardId}")->assertOk()->json();
        $this->assertCount(3, $show['items']);
        $bench = collect($show['benchmarks'])->firstWhere('tag', 'hook:problem-callout');
        $this->assertTrue($bench['enough']);              // ≥3 creatives
        $this->assertNotNull($bench['medianRoas']);       // median of 2.0/1.5/1.0 = 1.5×
        $this->assertEqualsWithDelta(1.5, $bench['medianRoas'], 0.001);
    }

    public function test_below_three_creatives_is_honest_not_enough(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $boardId = $this->postJson('/api/ads-library/boards', ['name' => 'B', 'brand_id' => $brand->id])->assertCreated()->json('id');

        $this->seedCreative($brand->id, 'ONLY1', 100.0, 300.0);
        $this->postJson("/api/ads-library/boards/{$boardId}/items", ['source' => 'internal', 'ref_id' => 'ONLY1', 'tags' => ['format:ugc-video']])->assertCreated();

        $bench = collect($this->getJson("/api/ads-library/boards/{$boardId}")->json('benchmarks'))->firstWhere('tag', 'format:ugc-video');
        $this->assertFalse($bench['enough']);
        $this->assertNull($bench['medianRoas']);
    }

    public function test_suggest_tags_degrades_quietly_without_an_llm_key(): void
    {
        // No LLM key on file → the endpoint returns enabled:false + the full
        // taxonomy (so the operator can still tag by hand) and never errors.
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $boardId = $this->postJson('/api/ads-library/boards', ['name' => 'D', 'brand_id' => $brand->id])->assertCreated()->json('id');
        $this->seedCreative($brand->id, 'S1', 100.0, 200.0);
        $itemId = $this->postJson("/api/ads-library/boards/{$boardId}/items", ['source' => 'internal', 'ref_id' => 'S1'])->assertCreated()->json('id');

        $res = $this->postJson("/api/ads-library/boards/{$boardId}/items/{$itemId}/suggest-tags")
            ->assertOk()
            ->json();

        $this->assertFalse($res['enabled']);
        $this->assertSame([], $res['suggested']);
        $this->assertContains('problem-callout', $res['taxonomy']); // flattened config taxonomy
    }

    public function test_suggest_tags_404s_when_item_belongs_to_another_board(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $a = $this->postJson('/api/ads-library/boards', ['name' => 'A', 'brand_id' => $brand->id])->assertCreated()->json('id');
        $b = $this->postJson('/api/ads-library/boards', ['name' => 'B', 'brand_id' => $brand->id])->assertCreated()->json('id');
        $this->seedCreative($brand->id, 'X1', 100.0, 200.0);
        $itemId = $this->postJson("/api/ads-library/boards/{$a}/items", ['source' => 'internal', 'ref_id' => 'X1'])->assertCreated()->json('id');

        // Item belongs to board A, not B → 404 (abort_unless board match).
        $this->postJson("/api/ads-library/boards/{$b}/items/{$itemId}/suggest-tags")->assertNotFound();
    }

    public function test_brief_assembles_reference_ads_and_hooks(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $boardId = $this->postJson('/api/ads-library/boards', ['name' => 'C', 'brand_id' => $brand->id])->assertCreated()->json('id');
        $this->postJson("/api/ads-library/boards/{$boardId}/items", ['source' => 'market', 'ref_id' => 'MKT1', 'tags' => ['angle:price']])->assertCreated();

        $briefId = $this->postJson("/api/ads-library/boards/{$boardId}/brief", ['title' => 'Shoot list'])->assertCreated()->json('id');
        $brief = $this->getJson("/api/ads-library/briefs/{$briefId}")->assertOk()->json();

        $this->assertSame('Shoot list', $brief['title']);
        $this->assertSame('draft', $brief['status']);
        $this->assertCount(1, $brief['blocks']['referenceAds']);
        $this->assertArrayHasKey('provenHooks', $brief['blocks']);
    }
}
