<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdSetDailyMetric;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Google\AdGroupFetcher as GoogleAdGroupFetcher;
use App\Platforms\Meta\AdSetFetcher as MetaAdSetFetcher;
use App\Platforms\TikTok\AdGroupFetcher as TikTokAdGroupFetcher;
use App\Services\Currency\FxService;
use App\Services\Sync\AdSetSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AdSetSync mapping contract (spec §4 Phase 3c): fetched native rows land in
 * ad_set_daily_metrics with the day's fx snapshot stamped, Meta-only reach /
 * frequency preserved as NULL (never 0) when absent, entity_kind carried, Google
 * impression-share passed through, and the (brand,platform,date,ad_set_id) upsert
 * idempotent. Fetchers are mocked — this tests the sync, not the HTTP.
 */
final class AdSetSyncTest extends TestCase
{
    use RefreshDatabase;

    private function connFor(Brand $brand, string $platform): PlatformConnection
    {
        $conn = (new PlatformConnection())->forceFill([
            'brand_id'    => $brand->id,
            'platform'    => $platform,
            'external_id' => "acct-{$platform}",
            'status'      => 'active',
            'credentials' => ['token' => 'x'],
        ]);
        $conn->save();
        $conn->setRelation('brand', $brand);

        return $conn;
    }

    private function sync(?MetaAdSetFetcher $meta = null, ?GoogleAdGroupFetcher $google = null, ?TikTokAdGroupFetcher $tiktok = null): AdSetSync
    {
        return new AdSetSync(
            $meta   ?? Mockery::mock(MetaAdSetFetcher::class),
            $google ?? Mockery::mock(GoogleAdGroupFetcher::class),
            $tiktok ?? Mockery::mock(TikTokAdGroupFetcher::class),
            app(FxService::class),
        );
    }

    public function test_meta_range_upserts_with_fx_stamped_and_missing_stays_null(): void
    {
        // AED is hard-pegged → cachedToUsd resolves a real non-1.0 rate with no DB
        // row, so this proves fx is actually stamped (not just USD=1.0).
        $brand = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'AED']);
        $conn  = $this->connFor($brand, 'meta');
        $from  = CarbonImmutable::parse('2026-06-01');
        $to    = $from->addDay();

        $rows = [
            [
                'date' => '2026-06-01', 'ad_set_id' => 'AS1', 'ad_set_name' => 'Prospecting',
                'campaign_id' => 'C1', 'entity_kind' => 'ad_set', 'status' => 'ACTIVE',
                'learning_status' => 'LEARNING', 'daily_budget' => 50.0, 'lifetime_budget' => null,
                'spend' => 100.0, 'impressions' => 1000, 'clicks' => 20, 'reach' => 800,
                'frequency' => 1.25, 'conversions' => 5, 'conversion_value' => 400.0, 'currency' => 'AED',
            ],
            [
                // Second day: no reach/frequency reported → must stay NULL, not 0.
                'date' => '2026-06-02', 'ad_set_id' => 'AS1', 'ad_set_name' => 'Prospecting',
                'campaign_id' => 'C1', 'entity_kind' => 'ad_set', 'spend' => 50.0, 'impressions' => 500,
                'clicks' => 10, 'reach' => null, 'frequency' => null, 'conversions' => 2,
                'conversion_value' => 120.0, 'currency' => 'AED',
            ],
        ];

        $meta = Mockery::mock(MetaAdSetFetcher::class);
        $meta->shouldReceive('fetchRange')->once()->andReturn($rows);

        $n = $this->sync(meta: $meta)->syncRange($conn, $from, $to);
        $this->assertSame(2, $n);

        $expectedFx = app(FxService::class)->cachedToUsd('AED', $from);
        $this->assertNotNull($expectedFx);
        $this->assertNotEqualsWithDelta(1.0, $expectedFx, 0.0001, 'AED peg should not be 1:1 with USD');

        $r1 = AdSetDailyMetric::where('brand_id', $brand->id)->where('date', '2026-06-01')->where('ad_set_id', 'AS1')->first();
        $this->assertNotNull($r1);
        $this->assertSame('meta', $r1->platform);
        $this->assertSame('ad_set', $r1->entity_kind);
        $this->assertSame('Prospecting', $r1->ad_set_name);
        $this->assertSame('C1', $r1->campaign_id);
        $this->assertSame('LEARNING', $r1->learning_status);
        $this->assertEqualsWithDelta(100.0, (float) $r1->spend, 0.001);
        $this->assertSame(800, (int) $r1->reach);
        $this->assertEqualsWithDelta(1.25, (float) $r1->frequency, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $r1->daily_budget, 0.001);
        $this->assertEqualsWithDelta($expectedFx, (float) $r1->fx_rate_to_usd, 0.0001);
        $this->assertTrue((bool) $r1->is_complete);

        $r2 = AdSetDailyMetric::where('brand_id', $brand->id)->where('date', '2026-06-02')->first();
        $this->assertNotNull($r2);
        $this->assertNull($r2->reach, 'missing reach is NULL, never 0');
        $this->assertNull($r2->frequency);

        // Idempotent: same key re-syncs as an update, not a duplicate.
        $meta2 = Mockery::mock(MetaAdSetFetcher::class);
        $meta2->shouldReceive('fetchRange')->once()->andReturn([array_merge($rows[0], ['spend' => 999.0])]);
        $this->sync(meta: $meta2)->syncRange($conn, $from, $from);

        $this->assertSame(2, AdSetDailyMetric::where('brand_id', $brand->id)->count());
        $this->assertEqualsWithDelta(
            999.0,
            (float) AdSetDailyMetric::where('brand_id', $brand->id)->where('date', '2026-06-01')->first()->spend,
            0.001,
        );
    }

    public function test_google_asset_group_carries_impression_share_and_entity_kind(): void
    {
        $brand = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'USD']);
        $conn  = $this->connFor($brand, 'google');
        $date  = CarbonImmutable::parse('2026-06-10');

        $google = Mockery::mock(GoogleAdGroupFetcher::class);
        $google->shouldReceive('fetchRange')->once()->andReturn([
            [
                'date' => '2026-06-10', 'ad_set_id' => 'AG1', 'ad_set_name' => 'Search — brand',
                'campaign_id' => 'GC1', 'entity_kind' => 'ad_set', 'status' => 'ENABLED',
                'spend' => 80.0, 'impressions' => 900, 'clicks' => 45, 'conversions' => 6,
                'conversion_value' => 300.0, 'search_impression_share' => 0.72,
                'search_budget_lost_is' => 0.10, 'currency' => 'USD',
            ],
            [
                // PMax asset group — no ad-group budget, entity_kind distinguishes it.
                'date' => '2026-06-10', 'ad_set_id' => 'ASG1', 'ad_set_name' => 'PMax — all',
                'campaign_id' => 'GC2', 'entity_kind' => 'asset_group', 'status' => 'ENABLED',
                'spend' => 210.0, 'impressions' => 5000, 'clicks' => 120, 'conversions' => 18,
                'conversion_value' => 1400.0, 'currency' => 'USD',
            ],
        ]);

        $n = $this->sync(google: $google)->syncRange($conn, $date, $date);
        $this->assertSame(2, $n);

        $ag = AdSetDailyMetric::where('ad_set_id', 'AG1')->first();
        $this->assertSame('ad_set', $ag->entity_kind);
        $this->assertEqualsWithDelta(0.72, (float) $ag->search_impression_share, 0.0001);
        $this->assertEqualsWithDelta(0.10, (float) $ag->search_budget_lost_is, 0.0001);
        $this->assertSame(1.0, (float) $ag->fx_rate_to_usd, 'USD stamps 1:1');

        $asg = AdSetDailyMetric::where('ad_set_id', 'ASG1')->first();
        $this->assertSame('asset_group', $asg->entity_kind);
        $this->assertNull($asg->search_impression_share, 'PMax exposes no impression share');
        $this->assertNull($asg->daily_budget);
    }

    public function test_non_ad_platform_writes_nothing(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $conn  = $this->connFor($brand, 'shopify');

        $meta = Mockery::mock(MetaAdSetFetcher::class);
        $meta->shouldNotReceive('fetchRange');

        $n = $this->sync(meta: $meta)->syncRange($conn, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-01'));
        $this->assertSame(0, $n);
        $this->assertSame(0, AdSetDailyMetric::count());
    }
}
