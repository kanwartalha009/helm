<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdCampaignDailyMetric;
use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\DailyMetric;
use App\Models\InventorySnapshot;
use App\Models\User;
use App\Reports\Support\AdAudit;
use App\Reports\Support\DeadInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke tests for the reporting engine (feature spec slice 2.0). Verify the
 * registry lists types, the Overall Performance builder returns the expected
 * payload shape, and the missing-≠-zero rule holds for an unconnected platform.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_registered_report_types(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('reports.0.key', 'overall-performance');
    }

    public function test_overall_performance_payload_and_missing_is_not_zero(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => 'Europe/Madrid',
            'status'        => 'active',
        ]);

        // One Shopify revenue row inside the last-30-day window.
        $row = new DailyMetric();
        $row->forceFill([
            'brand_id'    => $brand->id,
            'platform'    => 'shopify',
            'date'        => now()->subDays(3)->toDateString(),
            'total_sales' => 1000,
            'net_sales'   => 900,
            'orders'      => 5,
            'currency'    => 'EUR',
            'is_complete' => true,
            'pulled_at'   => now(),
        ])->save();

        Sanctum::actingAs($user);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/overall-performance?period=last30&compare=previous")
            ->assertOk()
            ->assertJsonPath('reportType', 'overall-performance')
            ->assertJsonPath('brand.slug', $brand->slug)
            ->assertJsonStructure([
                'currency',
                'period'   => ['label', 'start', 'end'],
                'kpis'     => ['revenue' => ['value', 'previous', 'deltaPct', 'deltaAbs'], 'adSpend', 'blendedRoas', 'orders', 'aov'],
                'revenueVsSpend',
                'byPlatform',
                'branding' => ['agency_name', 'accent', 'footer_text'],
            ]);

        // Revenue is the summed Shopify total_sales.
        $this->assertEquals(1000, $res->json('kpis.revenue.value'));

        // Missing ≠ zero: no Meta connection → connected:false with null spend,
        // never spend 0.
        $meta = collect($res->json('byPlatform'))->firstWhere('platform', 'meta');
        $this->assertFalse($meta['connected']);
        $this->assertNull($meta['spend']);

        // No commerce_daily_metrics yet → the 2.1 sections are absent (null),
        // never rendered as empty/zero. They appear only once the backfill runs.
        $this->assertNull($res->json('byRegion'));
        $this->assertNull($res->json('byProduct'));
        $this->assertNull($res->json('byCategory'));
    }

    public function test_commerce_breakdown_sections_appear_once_backfilled(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => 'Europe/Madrid',
            'status'        => 'active',
        ]);

        // Two country rows inside the last-30-day window (slice 2.1 grain).
        foreach ([['United States', 700.0, 7], ['Germany', 300.0, 3]] as [$country, $rev, $orders]) {
            (new CommerceDailyMetric())->forceFill([
                'brand_id'        => $brand->id,
                'date'            => now()->subDays(3)->toDateString(),
                'dimension_type'  => 'country',
                'dimension_key'   => $country,
                'dimension_label' => $country,
                'orders'          => $orders,
                'net_sales'       => $rev * 0.9,
                'total_sales'     => $rev,
                'currency'        => 'EUR',
                'fx_rate_to_usd'  => 1.1,
                'is_complete'     => true,
                'pulled_at'       => now(),
            ])->save();
        }

        Sanctum::actingAs($user);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/overall-performance?period=last30&compare=none")
            ->assertOk()
            ->assertJsonStructure([
                'byRegion' => ['rows' => [['key', 'label', 'revenue', 'orders', 'aov', 'share', 'previous', 'deltaPct']], 'total' => ['revenue', 'orders']],
            ]);

        // Ranked by revenue: US first at 70% share of the section total (1000).
        $this->assertEquals('United States', $res->json('byRegion.rows.0.key'));
        $this->assertEquals(700, $res->json('byRegion.rows.0.revenue'));
        $this->assertEquals(0.7, $res->json('byRegion.rows.0.share'));
        $this->assertEquals(1000, $res->json('byRegion.total.revenue'));

        // Only the country dimension was seeded — product/category stay absent.
        $this->assertNull($res->json('byProduct'));
        $this->assertNull($res->json('byCategory'));
    }

    public function test_region_trend_classification_and_matrix(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => 'Europe/Madrid',
            'status'        => 'active',
        ]);

        $seed = function (string $country, float $rev, string $date) use ($brand): void {
            (new CommerceDailyMetric())->forceFill([
                'brand_id'        => $brand->id,
                'date'            => $date,
                'dimension_type'  => 'country',
                'dimension_key'   => $country,
                'dimension_label' => $country,
                'orders'          => 5,
                'total_sales'     => $rev,
                'net_sales'       => $rev,
                'currency'        => 'EUR',
                'fx_rate_to_usd'  => 1.0,
                'is_complete'     => true,
                'pulled_at'       => now(),
            ])->save();
        };

        // last7 window = [now-7 .. now-1]; previous = [now-14 .. now-8].
        $cur  = now()->subDays(2)->toDateString();
        $prior = now()->subDays(10)->toDateString();

        // US collapsed (-70% → dead); Germany surged (+400% → growing).
        $seed('United States', 300, $cur);
        $seed('United States', 1000, $prior);
        $seed('Germany', 500, $cur);
        $seed('Germany', 100, $prior);

        Sanctum::actingAs($user);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/overall-performance?period=last7&compare=previous")
            ->assertOk();

        $rows = collect($res->json('byRegion.rows'))->keyBy('key');
        $this->assertSame('dead', $rows['United States']['trend']);
        $this->assertSame('growing', $rows['Germany']['trend']);

        // Matrix buckets carry the counts that drive the status cards.
        $matrix = collect($res->json('byRegion.matrix'))->keyBy('bucket');
        $this->assertSame(1, $matrix['dead']['count']);
        $this->assertSame(1, $matrix['growing']['count']);
    }

    public function test_ad_audit_classifies_campaigns_and_sums_waste(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => 'Europe/Madrid']);

        $seed = function (string $cid, string $name, float $spend, float $value) use ($brand): void {
            (new AdCampaignDailyMetric())->forceFill([
                'brand_id'         => $brand->id,
                'platform'         => 'meta',
                'date'             => now()->subDays(3)->toDateString(),
                'campaign_id'      => $cid,
                'campaign_name'    => $name,
                'spend'            => $spend,
                'impressions'      => 10000,
                'clicks'           => 200,
                'conversions'      => 20,
                'conversion_value' => $value,
                'currency'         => 'EUR',
                'fx_rate_to_usd'   => 1.0,
                'is_complete'      => true,
                'pulled_at'        => now(),
            ])->save();
        };

        $seed('c_dead', 'Losing campaign', 1000, 500);   // 0.5× ROAS → dead → waste
        $seed('c_win', 'Winning campaign', 1000, 4000);  // 4.0× ROAS → winner

        $audit = app(AdAudit::class)->forPlatform(
            $brand->id,
            'meta',
            now()->subDays(30)->toDateString(),
            now()->toDateString(),
            null,
            null,
            usd: false,
        );

        $this->assertNotNull($audit);
        $byId = collect($audit['campaigns'])->keyBy('id');
        $this->assertSame('dead', $byId['c_dead']['verdict']);
        $this->assertSame('winner', $byId['c_win']['verdict']);

        // Waste = spend on the sub-1× campaign only.
        $this->assertEqualsWithDelta(1000.0, $audit['waste']['amount'], 0.01);
        $this->assertSame(1, $audit['waste']['count']);

        // The action plan surfaces both a stop and a scale item.
        $kinds = collect($audit['actions'])->pluck('kind')->all();
        $this->assertContains('stop', $kinds);
        $this->assertContains('scale', $kinds);
    }

    public function test_dead_inventory_flags_stock_that_is_not_selling(): void
    {
        $brand = Brand::factory()->create(['timezone' => 'Europe/Madrid']);

        $seed = function (string $key, int $ending, int $sold) use ($brand): void {
            (new InventorySnapshot())->forceFill([
                'brand_id'        => $brand->id,
                'captured_on'     => now()->toDateString(),
                'dimension_type'  => 'product',
                'dimension_key'   => $key,
                'dimension_label' => $key,
                'ending_units'    => $ending,
                'units_sold'      => $sold,
                'window_days'     => 90,
                'pulled_at'       => now(),
            ])->save();
        };

        $seed('dead-ring', 50, 0);          // stock, zero sales → dead
        $seed('slow-necklace', 100, 5);     // ~1800 days of cover → slow
        $seed('healthy-bracelet', 20, 60);  // ~30 days of cover → healthy (excluded)

        $out = app(DeadInventory::class)->forDimension($brand->id, 'product');

        $this->assertNotNull($out);
        $byKey = collect($out['rows'])->keyBy('key');
        $this->assertSame('dead', $byKey['dead-ring']['status']);
        $this->assertSame('slow', $byKey['slow-necklace']['status']);
        $this->assertArrayNotHasKey('healthy-bracelet', $byKey->all());

        $this->assertSame(1, $out['deadCount']);
        $this->assertSame(50, $out['deadUnits']);
    }

    public function test_report_freshness_flags_stale_then_clears_after_sync(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => 'Europe/Madrid',
            'status'        => 'active',
        ]);

        $completeDay = function (string $date) use ($brand): void {
            (new DailyMetric())->forceFill([
                'brand_id'    => $brand->id,
                'platform'    => 'shopify',
                'date'        => $date,
                'total_sales' => 100,
                'orders'      => 2,
                'currency'    => 'EUR',
                'is_complete' => true,
                'pulled_at'   => now(),
            ])->save();
        };

        // Latest complete day is 4 days back → behind the window end (yesterday).
        $completeDay(now('Europe/Madrid')->subDays(4)->toDateString());

        Sanctum::actingAs($user);
        $stale = $this->getJson("/api/brands/{$brand->slug}/reports/overall-performance?period=last30&compare=none")->assertOk();
        $this->assertFalse($stale->json('freshness.upToDate'));
        $this->assertGreaterThan(0, $stale->json('freshness.staleDays'));

        // A fresh sync lands yesterday → up to date.
        $completeDay(now('Europe/Madrid')->subDay()->toDateString());
        $fresh = $this->getJson("/api/brands/{$brand->slug}/reports/overall-performance?period=last30&compare=none")->assertOk();
        $this->assertTrue($fresh->json('freshness.upToDate'));
        $this->assertSame(0, $fresh->json('freshness.staleDays'));
    }

    public function test_unknown_report_type_is_404(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson("/api/brands/{$brand->slug}/reports/not-a-real-report")
            ->assertNotFound();
    }
}
