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
 * The platform-scoped ads-audit report (campaign-level, shareable). Verifies
 * the per-platform blocks with hand-computed KPI math, the movers ranking by
 * absolute spend shift, the ?platform= scope (including the unconnected-
 * platform → empty case), the evidence-aware confidence tags with windowDays,
 * and the FAIL-CLOSED freshness gate when no campaign rows exist.
 */
class AdsAuditReportTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    private function makeBrand(): Brand
    {
        return Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => self::TZ,
            'status'        => 'active',
        ]);
    }

    private function connect(int $brandId, string $platform): void
    {
        DB::table('platform_connections')->insert([
            'brand_id'    => $brandId,
            'platform'    => $platform,
            'external_id' => "acct_{$platform}",
            'credentials' => '{}',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** Seed one ad_campaign_daily_metrics row via DB::table with date-only strings (sqlite trap). */
    private function seedCampaignDay(int $brandId, string $platform, string $date, string $cid, string $name, float $spend, float $value, int $conversions, float $fx = 1.0): void
    {
        DB::table('ad_campaign_daily_metrics')->insert([
            'brand_id'         => $brandId,
            'platform'         => $platform,
            'date'             => $date,
            'campaign_id'      => $cid,
            'campaign_name'    => $name,
            'spend'            => $spend,
            'impressions'      => 10000,
            'clicks'           => 200,
            'conversions'      => $conversions,
            'conversion_value' => $value,
            'currency'         => 'EUR',
            'fx_rate_to_usd'   => $fx,
            'is_complete'      => true,
            'pulled_at'        => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_builds_per_platform_blocks_with_kpi_math_movers_and_confidence(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $this->connect($brand->id, 'google');

        // period=last7 → [yesterday-6 .. yesterday]; previous → the 7 days
        // before that. Current rows land on yesterday so freshness is green.
        $yesterday = CarbonImmutable::now(self::TZ)->subDay()->startOfDay();
        $cur       = $yesterday->toDateString();
        $prev      = $yesterday->subDays(7)->toDateString();

        // Meta m1: 200 spend / 800 value / 10 conv now, 100 / 200 / 5 before →
        // ROAS 4.0 (winner, solid: 200 ≥ 150), spend shift |200-100| = 100.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm1', 'Prospecting', 200, 800, 10);
        $this->seedCampaignDay($brand->id, 'meta', $prev, 'm1', 'Prospecting', 100, 200, 5);
        // Meta m2: 60 spend / 30 value / 2 conv, no prior rows → ROAS 0.5
        // (dead) but EARLY — only 60 USD of evidence (< 150). Shift 60.
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm2', 'New test', 60, 30, 2);
        // Google g1: no comparison rows → null-safe previous.
        $this->seedCampaignDay($brand->id, 'google', $cur, 'g1', 'Brand search', 300, 900, 3);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7&compare=previous")
            ->assertOk()
            ->assertJsonPath('reportType', 'ads-audit')
            ->assertJsonPath('brand.slug', $brand->slug)
            ->assertJsonPath('platformFilter', null)
            ->assertJsonPath('hasData', true)
            ->assertJsonStructure([
                'currency',
                'period'     => ['label', 'start', 'end'],
                'comparison' => ['label', 'start', 'end'],
                'platforms'  => [['platform', 'kpis' => ['spend', 'conversionValue', 'roas', 'purchases', 'cpa'], 'audit', 'movers']],
                'freshness'  => ['upToDate', 'lastSynced', 'staleDays', 'windowEnd'],
            ]);

        $platforms = collect($res->json('platforms'))->keyBy('platform');
        $this->assertCount(2, $platforms);

        // Meta KPIs, hand-computed: spend 260 vs 100 (+160%), value 830 vs 200
        // (+315%), ROAS 830/260 = 3.19 vs 2.0 (Δ +1.19 abs), purchases 12 vs 5
        // (+140%), CPA 260/12 = 21.67 vs 20.0.
        $meta = $platforms['meta'];
        $this->assertEquals(260.0, $meta['kpis']['spend']['value']);
        $this->assertEquals(100.0, $meta['kpis']['spend']['previous']);
        $this->assertEqualsWithDelta(160.0, $meta['kpis']['spend']['deltaPct'], 0.1);
        $this->assertEquals(830.0, $meta['kpis']['conversionValue']['value']);
        $this->assertEqualsWithDelta(315.0, $meta['kpis']['conversionValue']['deltaPct'], 0.1);
        $this->assertEquals(3.19, $meta['kpis']['roas']['value']);
        $this->assertEquals(2.0, $meta['kpis']['roas']['previous']);
        $this->assertEquals(1.19, $meta['kpis']['roas']['deltaAbs']);
        $this->assertSame(12, $meta['kpis']['purchases']['value']);
        $this->assertSame(5, $meta['kpis']['purchases']['previous']);
        $this->assertEqualsWithDelta(140.0, $meta['kpis']['purchases']['deltaPct'], 0.1);
        $this->assertEquals(21.67, $meta['kpis']['cpa']['value']);
        $this->assertEquals(20.0, $meta['kpis']['cpa']['previous']);

        // Movers ranked by |spend shift|: m1 (100) before m2 (60), each carrying
        // the AdAudit verdict + evidence confidence.
        $movers = $meta['movers'];
        $this->assertSame(['m1', 'm2'], array_column($movers, 'campaignId'));
        $this->assertEquals(200.0, $movers[0]['spend']);
        $this->assertEquals(100.0, $movers[0]['prevSpend']);
        $this->assertEqualsWithDelta(100.0, $movers[0]['spendDeltaPct'], 0.1);
        $this->assertEquals(4.0, $movers[0]['roas']);
        $this->assertEquals(2.0, $movers[0]['prevRoas']);
        $this->assertSame('winner', $movers[0]['verdict']);
        $this->assertSame('solid', $movers[0]['confidence']); // 200 USD ≥ 150 evidence floor
        $this->assertSame('dead', $movers[1]['verdict']);
        $this->assertSame('early', $movers[1]['confidence']); // 60 USD < 150 — under-evidenced
        $this->assertNull($movers[1]['prevSpend']);           // missing ≠ zero

        // The embedded AdAudit block carries the window size + tagged campaigns.
        $this->assertSame(7, $meta['audit']['windowDays']);
        $auditById = collect($meta['audit']['campaigns'])->keyBy('id');
        $this->assertSame('solid', $auditById['m1']['confidence']);
        $this->assertSame('early', $auditById['m2']['confidence']);

        // Google: no comparison rows → previous/deltas null, never 0-based.
        $google = $platforms['google'];
        $this->assertEquals(300.0, $google['kpis']['spend']['value']);
        $this->assertNull($google['kpis']['spend']['previous']);
        $this->assertNull($google['kpis']['spend']['deltaPct']);
        $this->assertNull($google['movers'][0]['prevSpend']);

        // Freshness: campaign rows reach the window end (yesterday).
        $this->assertTrue($res->json('freshness.upToDate'));
        $this->assertSame($cur, $res->json('freshness.lastSynced'));
    }

    public function test_platform_filter_scopes_to_one_platform(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');
        $this->connect($brand->id, 'google');

        $cur = CarbonImmutable::now(self::TZ)->subDay()->toDateString();
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm1', 'Prospecting', 200, 800, 10);
        $this->seedCampaignDay($brand->id, 'google', $cur, 'g1', 'Brand search', 300, 900, 3);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7&platform=google")
            ->assertOk()
            ->assertJsonPath('platformFilter', 'google')
            ->assertJsonPath('hasData', true);

        $platforms = $res->json('platforms');
        $this->assertCount(1, $platforms);
        $this->assertSame('google', $platforms[0]['platform']);
    }

    public function test_filter_on_an_unconnected_platform_yields_no_data_not_an_error(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');

        $cur = CarbonImmutable::now(self::TZ)->subDay()->toDateString();
        $this->seedCampaignDay($brand->id, 'meta', $cur, 'm1', 'Prospecting', 200, 800, 10);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7&platform=tiktok")
            ->assertOk()
            ->assertJsonPath('platformFilter', 'tiktok')
            ->assertJsonPath('hasData', false);

        $this->assertSame([], $res->json('platforms'));
    }

    public function test_freshness_fails_closed_when_no_campaign_data_exists(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->connect($brand->id, 'meta');

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/ads-audit?period=last7")
            ->assertOk()
            ->assertJsonPath('hasData', false);

        // Empty ad_campaign_daily_metrics → the gate holds the report back:
        // never "up to date" without a synced row to prove it.
        $this->assertFalse($res->json('freshness.upToDate'));
        $this->assertNull($res->json('freshness.lastSynced'));
        $this->assertSame([], $res->json('platforms'));
    }
}
