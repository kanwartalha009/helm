<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdLibraryAd;
use App\Models\AdLibraryPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ads Library Phase 3 — market endpoint. Reads the stored corpus, collapses to
 * ONE card per concept (variant count exposed), and NEVER exposes spend/ROAS
 * (commercial ads have none — proxy signals only). Tracking is admin/manager.
 */
final class AdsLibraryMarketTest extends TestCase
{
    use RefreshDatabase;

    private function ad(string $id, string $hash, array $o = []): void
    {
        AdLibraryAd::create(array_merge([
            'ad_archive_id' => $id, 'page_id' => 'P1', 'page_name' => 'Comp', 'niche' => 'footwear',
            'concept_hash' => $hash, 'is_active' => true, 'delivery_start' => '2026-06-01',
            'eu_total_reach' => 1000, 'signal_score' => 0.5, 'creative_bodies' => ['hook text'],
        ], $o));
    }

    public function test_collapses_to_one_card_per_concept_with_variant_count(): void
    {
        $this->ad('V1', 'CONCEPT_A', ['signal_score' => 0.9]);
        $this->ad('V2', 'CONCEPT_A', ['signal_score' => 0.4]); // same concept, lower score
        $this->ad('B1', 'CONCEPT_B', ['signal_score' => 0.7]);

        Sanctum::actingAs(User::factory()->create(['role' => 'team_member']));
        $res = $this->getJson('/api/ads-library/market')->assertOk()->json();

        $this->assertCount(2, $res['rows']);      // 2 concepts, not 3 ads
        $this->assertSame(2, $res['total']);

        $rows = collect($res['rows'])->keyBy('adArchiveId');
        // The highest-scoring variant represents concept A, with 2 variants behind it.
        $this->assertArrayHasKey('V1', $rows);
        $this->assertSame(2, $rows['V1']['variants']);

        // Proxy only — never spend/roas.
        $this->assertArrayNotHasKey('spend', $res['rows'][0]);
        $this->assertArrayNotHasKey('roas', $res['rows'][0]);
        $this->assertArrayHasKey('scoreWeights', $res);
        $this->assertStringContainsString('EU delivery only', $res['coverageNote']);
    }

    public function test_rising_sorts_by_reach_velocity_nulls_last(): void
    {
        // Rising = eu_total_reach ÷ longevity_days (a disclosed Proxy sort key).
        // FAST: 900 reach ÷ 3 days = 300/day (young, fast) → ranks FIRST despite
        // lower absolute reach than SLOW. SLOW: 5000 ÷ 100 = 50/day. NONE: null
        // reach → velocity null → sorts LAST (never treated as 0).
        $this->ad('FAST', 'C_FAST', ['eu_total_reach' => 900, 'longevity_days' => 3]);
        $this->ad('SLOW', 'C_SLOW', ['eu_total_reach' => 5000, 'longevity_days' => 100]);
        $this->ad('NONE', 'C_NONE', ['eu_total_reach' => null, 'longevity_days' => 10]);

        Sanctum::actingAs(User::factory()->create(['role' => 'team_member']));
        $res = $this->getJson('/api/ads-library/market?sort=rising')->assertOk()->json();

        $this->assertSame('rising', $res['sort']);
        $order = array_column($res['rows'], 'adArchiveId');
        $this->assertSame(['FAST', 'SLOW', 'NONE'], $order); // velocity DESC, null last
    }

    public function test_tracking_is_admin_manager_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'team_member']));
        $this->postJson('/api/ads-library/pages', ['page_id' => '123', 'niche' => 'footwear'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $this->postJson('/api/ads-library/pages', ['page_id' => '123', 'niche' => 'footwear'])->assertCreated();
        $this->assertDatabaseHas('ad_library_pages', ['page_id' => '123', 'status' => 'active']);

        // Untrack → paused (history kept).
        $page = AdLibraryPage::first();
        $this->deleteJson("/api/ads-library/pages/{$page->id}")->assertOk();
        $this->assertDatabaseHas('ad_library_pages', ['id' => $page->id, 'status' => 'paused']);
    }
}
