<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Services\Recon\AdsSpendRecon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ads-spend reconciliation self-check (Kanwar, 2026-07-22 — Bruna amboise
 * incident). Proves the invariant engine flags a dropping/attribution bug: when
 * the attributed roll-up (ad_product_daily) falls short of the source of truth
 * (daily_metrics account spend), the pair grades RED with the exact € + % diff,
 * while a table that reconciles exactly grades ok.
 */
final class AdsSpendReconTest extends TestCase
{
    use RefreshDatabase;

    private const D1 = '2026-07-01';
    private const D2 = '2026-07-02';

    private function daily(int $brandId, string $platform, string $date, float $spend): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $brandId, 'platform' => $platform, 'date' => $date,
            'spend' => $spend, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0,
            'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function product(int $brandId, string $date, string $key, float $spend): void
    {
        DB::table('ad_product_daily')->insert([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => $date,
            'product_key' => $key, 'spend' => $spend, 'ads_count' => 1,
            'currency' => 'EUR', 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    private function campaign(int $brandId, string $platform, string $date, string $campaignId, float $spend): void
    {
        DB::table('ad_campaign_daily_metrics')->insert([
            'brand_id' => $brandId, 'platform' => $platform, 'date' => $date,
            'campaign_id' => $campaignId, 'spend' => $spend, 'currency' => 'EUR',
        ]);
    }

    private function creative(int $brandId, string $date, string $adId, float $spend): void
    {
        DB::table('ad_creative_daily')->insert([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => $date,
            'ad_id' => $adId, 'spend' => $spend, 'currency' => 'EUR',
        ]);
    }

    private function pair(array $report, string $key): array
    {
        foreach ($report['pairs'] as $p) {
            if ($p['key'] === $key) {
                return $p;
            }
        }
        $this->fail("pair {$key} not found");
    }

    public function test_it_flags_a_dropping_bug_red_and_a_clean_table_ok(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'EUR']);

        // Source of truth: Meta account spend 1000/day → 2000 over the window.
        $this->daily($brand->id, 'meta', self::D1, 1000);
        $this->daily($brand->id, 'meta', self::D2, 1000);

        // Attributed product roll-up: D1 reconciles exactly (600 + 400 = 1000);
        // D2 DROPS spend (500 + 350 = 850 vs 1000) — the amboise-partnership bug.
        $this->product($brand->id, self::D1, 'amboise-stud', 600);
        $this->product($brand->id, self::D1, AdSpendReconStub::OTHER, 400);
        $this->product($brand->id, self::D2, 'amboise-stud', 500);
        $this->product($brand->id, self::D2, AdSpendReconStub::OTHER, 350);

        // Campaign roll-up reconciles exactly to the account (a clean table).
        $this->campaign($brand->id, 'meta', self::D1, 'c1', 1000);
        $this->campaign($brand->id, 'meta', self::D2, 'c1', 1000);
        // Creatives reconcile exactly to campaigns.
        $this->creative($brand->id, self::D1, 'a1', 1000);
        $this->creative($brand->id, self::D2, 'a1', 1000);

        $report = app(AdsSpendRecon::class)->forBrand($brand, self::D1, self::D2);

        // product_vs_account: 1850 vs 2000 → 7.5% → RED, and the diff is exact.
        $prod = $this->pair($report, 'product_vs_account');
        $this->assertSame('red', $prod['level']);
        $this->assertEqualsWithDelta(1850.0, $prod['actualTotal'], 0.001);
        $this->assertEqualsWithDelta(2000.0, $prod['referenceTotal'], 0.001);
        $this->assertEqualsWithDelta(-150.0, $prod['diff'], 0.001);
        $this->assertEqualsWithDelta(7.5, $prod['driftPct'], 0.001);
        // Per-day: D1 ok (exact), D2 red (15% short).
        $byDate = collect($prod['days'])->keyBy('date');
        $this->assertSame('ok', $byDate[self::D1]['level']);
        $this->assertSame('red', $byDate[self::D2]['level']);
        $this->assertEqualsWithDelta(15.0, $byDate[self::D2]['driftPct'], 0.001);

        // campaign_vs_account:meta and creative_vs_campaign:meta reconcile → ok.
        $this->assertSame('ok', $this->pair($report, 'campaign_vs_account:meta')['level']);
        $this->assertSame('ok', $this->pair($report, 'creative_vs_campaign:meta')['level']);

        // Worst level across the brand rolls up to red.
        $this->assertSame('red', $report['worstLevel']);
    }

    public function test_small_drift_grades_amber_not_red(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'EUR']);

        // Google account 1000; campaigns 985 → 1.5% → amber (>1%, ≤5%).
        $this->daily($brand->id, 'google', self::D1, 1000);
        $this->campaign($brand->id, 'google', self::D1, 'g1', 985);

        $report = app(AdsSpendRecon::class)->forBrand($brand, self::D1, self::D1);
        $g = $this->pair($report, 'campaign_vs_account:google');
        $this->assertSame('amber', $g['level']);
        $this->assertEqualsWithDelta(1.5, $g['driftPct'], 0.001);
    }
}

/** Local alias so the test reads clearly without importing the fetcher constant. */
final class AdSpendReconStub
{
    public const OTHER = '__other';
}
