<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Services\Rules\DataQuality;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-1.3 — the data-quality score. Proves each component moves on MEASURED facts,
 * that an inapplicable component is excluded (not scored 0), that the gate flips at
 * the configured threshold, and — the master plan's proof — that the score visibly
 * RISES after a backfill lands history.
 */
final class DataQualityTest extends TestCase
{
    use RefreshDatabase;

    private function connect(Brand $brand, string $platform): void
    {
        DB::table('platform_connections')->insert([
            'brand_id'    => $brand->id,
            'platform'    => $platform,
            'external_id' => "acct_{$platform}",
            'credentials' => '{}',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** One complete daily_metrics row. */
    private function daily(Brand $brand, string $platform, string $date): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => $platform, 'date' => $date,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function score(Brand $brand): array
    {
        return app(DataQuality::class)->forBrand($brand->fresh());
    }

    public function test_a_brand_with_nothing_connected_scores_low_and_fails_the_gate(): void
    {
        $brand = Brand::factory()->create(['gross_margin_pct' => null]);

        $q = $this->score($brand);
        $this->assertSame(0, $q['score']);
        $this->assertFalse($q['meetsGate']);
        $this->assertSame('poor', $q['tier']);
    }

    public function test_inapplicable_components_are_excluded_not_scored_zero(): void
    {
        // Shopify only, no ad platform → the ad-grain component CANNOT apply. It must
        // drop out of the denominator; scoring it 0 would punish the brand for a grain
        // it cannot have.
        $brand = Brand::factory()->create(['gross_margin_pct' => 50]);
        $this->connect($brand, 'shopify');
        $this->daily($brand, 'shopify', CarbonImmutable::now()->subDay()->toDateString());

        $q     = $this->score($brand);
        $grain = collect($q['components'])->firstWhere('key', 'grain');

        $this->assertFalse($grain['applicable']);
        // With grain excluded, a fresh Shopify-only brand still scores respectably.
        $this->assertGreaterThan(0, $q['score']);
    }

    public function test_freshness_decays_with_staleness(): void
    {
        $fresh = Brand::factory()->create();
        $this->connect($fresh, 'shopify');
        $this->daily($fresh, 'shopify', CarbonImmutable::now()->subDay()->toDateString());

        $stale = Brand::factory()->create();
        $this->connect($stale, 'shopify');
        $this->daily($stale, 'shopify', CarbonImmutable::now()->subDays(30)->toDateString());

        $f = collect($this->score($fresh)['components'])->firstWhere('key', 'freshness');
        $s = collect($this->score($stale)['components'])->firstWhere('key', 'freshness');

        $this->assertEqualsWithDelta(1.0, $f['ratio'], 0.001);  // within grace
        $this->assertEqualsWithDelta(0.0, $s['ratio'], 0.001);  // past the zero point
        $this->assertSame('history', $s['fix']);                // offers the backfill that fixes it
    }

    public function test_score_rises_after_a_backfill_lands_history(): void
    {
        // The master plan's proof: quality must visibly move when data arrives.
        $brand = Brand::factory()->create(['gross_margin_pct' => 50]);
        $this->connect($brand, 'shopify');
        $this->daily($brand, 'shopify', CarbonImmutable::now()->subDay()->toDateString());

        $before = $this->score($brand);
        $beforeHistory = collect($before['components'])->firstWhere('key', 'history');
        $this->assertLessThan(1.0, $beforeHistory['ratio']);   // ~1 day of history

        // "Backfill" 12 months of daily rows (one per month is enough to move MIN(date)).
        for ($m = 1; $m <= 12; $m++) {
            $this->daily($brand, 'shopify', CarbonImmutable::now()->subMonths($m)->toDateString());
        }

        $after = $this->score($brand);
        $this->assertGreaterThan($before['score'], $after['score']);
        $this->assertEqualsWithDelta(1.0, collect($after['components'])->firstWhere('key', 'history')['ratio'], 0.001);
    }

    public function test_gate_flips_at_the_configured_threshold(): void
    {
        config()->set('quality.threshold', 100);   // nothing short of perfect passes
        $brand = Brand::factory()->create(['gross_margin_pct' => 50]);
        $this->connect($brand, 'shopify');
        $this->daily($brand, 'shopify', CarbonImmutable::now()->subDay()->toDateString());

        $this->assertFalse($this->score($brand)['meetsGate']);
        $this->assertFalse(app(DataQuality::class)->meetsGate($brand->fresh()));

        config()->set('quality.threshold', 1);     // almost anything passes
        $this->assertTrue($this->score($brand)['meetsGate']);
    }

    public function test_endpoints_return_the_breakdown_and_the_brand_list(): void
    {
        $brand = Brand::factory()->create(['status' => 'active', 'gross_margin_pct' => 50]);
        $this->connect($brand, 'shopify');
        $this->daily($brand, 'shopify', CarbonImmutable::now()->subDay()->toDateString());

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $show = $this->getJson("/api/brands/{$brand->slug}/data-quality")->assertOk()->json();
        $this->assertArrayHasKey('score', $show);
        $this->assertArrayHasKey('meetsGate', $show);
        $this->assertCount(5, $show['components']);   // platforms, freshness, history, grain, costs

        $list = $this->getJson('/api/brands-quality')->assertOk()->json();
        $this->assertSame($brand->id, $list['rows'][0]['brandId']);
        $this->assertSame($show['score'], $list['rows'][0]['score']);
    }
}
