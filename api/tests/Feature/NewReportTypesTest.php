<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two new client report types (weekly performance + creative performance).
 * Verifies the registry lists them, the weekly builder's week window / WoW
 * deltas / D-005 revenue math / incomplete-day nulls, the creative builder's
 * ordering + deterministic fatigue rule + missing-platform absence, and that
 * both degrade cleanly (freshness contract, no 500) on an empty brand.
 */
class NewReportTypesTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    public function test_registry_lists_five_report_types(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $res  = $this->getJson('/api/reports')->assertOk();
        $keys = collect($res->json('reports'))->pluck('key')->all();

        $this->assertCount(5, $keys);
        $this->assertContains('weekly', $keys);
        $this->assertContains('creatives', $keys);
        $this->assertContains('ads-audit', $keys);

        $labels = collect($res->json('reports'))->keyBy('key');
        $this->assertSame('Weekly performance', $labels['weekly']['label']);
        $this->assertSame('Creative performance', $labels['creatives']['label']);
        $this->assertSame('Ads audit', $labels['ads-audit']['label']);
    }

    /** The last COMPLETE Mon–Sun ISO week in the brand's timezone. */
    private function lastCompleteWeekStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfWeek(CarbonInterface::MONDAY)->subWeek()->startOfDay();
    }

    /** Seed one shopify daily_metrics row via DB::table with date-only strings (sqlite trap). */
    private function seedShopifyDay(int $brandId, string $date, float $totalSales, float $refunds, int $orders, bool $complete = true): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id'       => $brandId,
            'platform'       => 'shopify',
            'date'           => $date,
            'total_sales'    => $totalSales,
            'refunds_amount' => $refunds,
            'orders'         => $orders,
            'currency'       => 'EUR',
            'fx_rate_to_usd' => 1.0,
            'is_complete'    => $complete,
            'pulled_at'      => now(),
        ]);
    }

    public function test_weekly_report_window_wow_deltas_and_incomplete_day_nulls(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => self::TZ,
            'status'        => 'active',
        ]);

        $weekStart = $this->lastCompleteWeekStart();
        $prevStart = $weekStart->subWeek();

        // Report week: 6 complete days + Sunday incomplete. D-005: total revenue
        // adds refunds back, so each complete day = 100 + 20 = 120.
        for ($i = 0; $i < 6; $i++) {
            $this->seedShopifyDay($brand->id, $weekStart->addDays($i)->toDateString(), 100, 20, 2);
        }
        $this->seedShopifyDay($brand->id, $weekStart->addDays(6)->toDateString(), 999, 0, 9, complete: false);

        // Previous week: 7 complete days at 50 + 10 = 60 each → 420 total.
        for ($i = 0; $i < 7; $i++) {
            $this->seedShopifyDay($brand->id, $prevStart->addDays($i)->toDateString(), 50, 10, 1);
        }

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/weekly")
            ->assertOk()
            ->assertJsonPath('reportType', 'weekly')
            ->assertJsonPath('week.start', $weekStart->toDateString())
            ->assertJsonPath('week.end', $weekStart->addDays(6)->toDateString())
            ->assertJsonStructure([
                'week' => ['label', 'start', 'end'],
                'comparison' => ['previous'],
                'kpis' => ['totalRevenue' => ['value', 'previous', 'deltaPct', 'deltaAbs', 'lastYear'], 'adSpend', 'blendedRoas', 'orders', 'aov'],
                'dailySeries',
                'spendByPlatform',
                'freshness' => ['upToDate', 'lastSynced', 'staleDays', 'windowEnd'],
            ]);

        // D-005 revenue: KPI totals sum every synced day of the week, refunds
        // added back — 6 × 120 + (999 + 0) = 1719.
        $this->assertEquals(1719.0, $res->json('kpis.totalRevenue.value'));
        $this->assertEquals(420.0, $res->json('kpis.totalRevenue.previous'));
        // WoW delta: (1719 − 420) / 420 = +309.3%.
        $this->assertEqualsWithDelta(309.3, $res->json('kpis.totalRevenue.deltaPct'), 0.1);
        // Orders WoW: 21 vs 7 → +200%.
        $this->assertSame(21, $res->json('kpis.orders.value'));
        $this->assertEqualsWithDelta(200.0, $res->json('kpis.orders.deltaPct'), 0.1);

        // No rows a year back → same-week-last-year comparison is null, never 0.
        $this->assertNull($res->json('comparison.lastYear'));
        $this->assertNull($res->json('kpis.totalRevenue.lastYear'));

        // Daily series: 7 rows; the incomplete Sunday renders NULL revenue
        // (missing ≠ zero), complete days carry the D-005 figure. No ad platform
        // rows → spend is null, never 0.
        $series = $res->json('dailySeries');
        $this->assertCount(7, $series);
        $this->assertEquals(120.0, $series[0]['revenue']);
        $this->assertTrue($series[0]['complete']);
        $this->assertNull($series[6]['revenue']);
        $this->assertFalse($series[6]['complete']);
        $this->assertNull($series[0]['spend']);

        // Unconnected ad platforms report connected:false with null spend.
        $meta = collect($res->json('spendByPlatform'))->firstWhere('platform', 'meta');
        $this->assertFalse($meta['connected']);
        $this->assertNull($meta['spend']);
    }

    /** Seed one ad_creative_daily row via DB::table with date-only strings (sqlite trap). */
    private function seedCreativeDay(int $brandId, string $platform, string $date, string $adId, string $name, float $spend, float $value, int $impressions = 10000, int $clicks = 200): void
    {
        DB::table('ad_creative_daily')->insert([
            'brand_id'         => $brandId,
            'platform'         => $platform,
            'date'             => $date,
            'ad_id'            => $adId,
            'ad_name'          => $name,
            'media_type'       => 'video',
            'spend'            => $spend,
            'impressions'      => $impressions,
            'clicks'           => $clicks,
            'video_3s'         => 3000,
            'thruplays'        => 900,
            'add_to_cart'      => 30,
            'conversions'      => 10,
            'conversion_value' => $value,
            'currency'         => 'EUR',
            'fx_rate_to_usd'   => 1.0,
            'is_complete'      => true,
            'pulled_at'        => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_creative_report_ordering_fatigue_rule_and_platform_absence(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => self::TZ,
            'status'        => 'active',
        ]);

        // period=last7 → [yesterday-6 .. yesterday]; compare=previous → the 7
        // days before that. Date-only strings in the brand's timezone.
        $yesterday = CarbonImmutable::now(self::TZ)->subDay()->startOfDay();
        $cur       = $yesterday->subDays(2)->toDateString();
        $prev      = $yesterday->subDays(9)->toDateString();

        // ad_a: 500 spend, ROAS 2.0× now vs 4.0× last window (−50% ≥ 30% drop,
        // spend ≥ the 100 USD floor) → fatigued.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ad_a', 'Hero video', 500, 1000);
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'ad_a', 'Hero video', 400, 1600);

        // ad_b: 300 spend, ROAS 3.0× now vs 3.3× last window (−9% < 30%) → NOT
        // fatigued even though spend clears the floor.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ad_b', 'Steady video', 300, 900);
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'ad_b', 'Steady video', 300, 990);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/creatives?period=last7&compare=previous")
            ->assertOk()
            ->assertJsonPath('reportType', 'creatives');

        $platforms = collect($res->json('platforms'));
        // Meta has rows; TikTok has none → absent, never an empty block.
        $this->assertCount(1, $platforms);
        $meta = $platforms->firstWhere('platform', 'meta');
        $this->assertNotNull($meta);

        // Summary + top creative ordering (by spend): ad_a (500) before ad_b (300).
        $this->assertSame(2, $meta['summary']['creatives']);
        $this->assertEquals(800.0, $meta['summary']['spend']);
        $this->assertSame('ad_a', $meta['topCreatives'][0]['id']);
        $this->assertSame('ad_b', $meta['topCreatives'][1]['id']);
        $this->assertEquals(2.0, $meta['topCreatives'][0]['roas']);
        $this->assertEquals(0.625, $meta['topCreatives'][0]['spendShare']);
        // Thumbstop = video_3s ÷ impressions (30%), hold = thruplays ÷ video_3s (30%).
        $this->assertEquals(30.0, $meta['topCreatives'][0]['thumbstop']);
        $this->assertEquals(30.0, $meta['topCreatives'][0]['hold']);

        // Fatigue rule fires for ad_a only.
        $fatiguedIds = collect($meta['fatigued'])->pluck('id')->all();
        $this->assertSame(['ad_a'], $fatiguedIds);
        $this->assertStringContainsString('ROAS fell', $meta['fatigued'][0]['reason']);

        // Media mix: all spend is video.
        $this->assertSame('video', $meta['mediaMix'][0]['mediaType']);
        $this->assertEquals(1.0, $meta['mediaMix'][0]['share']);
    }

    public function test_creative_fatigue_respects_spend_floor_and_new_creatives(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);

        $yesterday = CarbonImmutable::now(self::TZ)->subDay()->startOfDay();
        $cur       = $yesterday->subDays(2)->toDateString();
        $prev      = $yesterday->subDays(9)->toDateString();

        // ad_small: huge ROAS drop but only 50 spend — below the 100 USD floor.
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ad_small', 'Small test', 50, 50);
        $this->seedCreativeDay($brand->id, 'meta', $prev, 'ad_small', 'Small test', 50, 500);

        // ad_new: big spend, poor ROAS, but no comparison-window rows — a new
        // creative has nothing to fall from, so it is never "fatigued".
        $this->seedCreativeDay($brand->id, 'meta', $cur, 'ad_new', 'Fresh launch', 400, 200);

        Sanctum::actingAs($user);
        $res  = $this->getJson("/api/brands/{$brand->slug}/reports/creatives?period=last7&compare=previous")->assertOk();
        $meta = collect($res->json('platforms'))->firstWhere('platform', 'meta');

        $this->assertSame([], $meta['fatigued']);
    }

    public function test_weekly_campaign_movers_actions_and_creative_scale_rule(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);
        DB::table('platform_connections')->insert([
            'brand_id'    => $brand->id,
            'platform'    => 'meta',
            'external_id' => 'act_123',
            'credentials' => '{}',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // One campaign: 4000 value on 800 spend last week (5.0×) collapsing to
        // 200 on 1000 (0.2× → the AdAudit "dead" rule) this week.
        $weekStart = $this->lastCompleteWeekStart();
        foreach ([[$weekStart, 1000.0, 200.0], [$weekStart->subWeek(), 800.0, 4000.0]] as [$d, $spend, $value]) {
            DB::table('ad_campaign_daily_metrics')->insert([
                'brand_id'         => $brand->id,
                'platform'         => 'meta',
                'date'             => $d->addDays(2)->toDateString(),
                'campaign_id'      => 'c1',
                'campaign_name'    => 'Prospecting',
                'spend'            => $spend,
                'impressions'      => 10000,
                'clicks'           => 100,
                'conversions'      => 10,
                'conversion_value' => $value,
                'currency'         => 'EUR',
                'fx_rate_to_usd'   => 1.0,
                'is_complete'      => true,
                'pulled_at'        => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        Sanctum::actingAs($user);
        $weekly = $this->getJson("/api/brands/{$brand->slug}/reports/weekly")->assertOk();

        $mover = $weekly->json('campaignMovers.0');
        $this->assertSame('c1', $mover['id']);
        $this->assertEquals(1000.0, $mover['spend']);
        $this->assertEquals(0.2, $mover['roas']);
        $this->assertEquals(5.0, $mover['prevRoas']);
        $this->assertEqualsWithDelta(25.0, $mover['spendDelta'], 0.1); // 800 → 1000

        // The dead campaign yields a "stop" action, tagged with its platform.
        $stop = collect($weekly->json('actions'))->firstWhere('kind', 'stop');
        $this->assertNotNull($stop);
        $this->assertSame('meta', $stop['platform']);

        // Scale rule: s3 at 6.0× ≥ 2 × the 1.2× platform median with ≥ 50 USD
        // spend; s1 (1.0×) and s2 (1.2×) don't qualify.
        $yesterday = CarbonImmutable::now(self::TZ)->subDay()->startOfDay();
        $cur       = $yesterday->subDays(2)->toDateString();
        $this->seedCreativeDay($brand->id, 'meta', $cur, 's1', 'Flat A', 100, 100);
        $this->seedCreativeDay($brand->id, 'meta', $cur, 's2', 'Flat B', 100, 120);
        $this->seedCreativeDay($brand->id, 'meta', $cur, 's3', 'Winner', 200, 1200);

        $cre  = $this->getJson("/api/brands/{$brand->slug}/reports/creatives?period=last7&compare=previous")->assertOk();
        $meta = collect($cre->json('platforms'))->firstWhere('platform', 'meta');

        $scaleIds = collect($meta['scaleCandidates'])->pluck('id')->all();
        $this->assertSame(['s3'], $scaleIds);
        $this->assertEquals(1.2, $meta['scaleCandidates'][0]['platformMedian']);
    }

    public function test_weekly_week_selector_shifts_the_window_and_lists_available_weeks(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);

        $defaultStart  = $this->lastCompleteWeekStart();
        $selectedStart = $defaultStart->subWeeks(2);

        // Rows in the default week (120/day basis) and the selected older week
        // (60/day) plus ITS previous week (30/day) so the WoW delta is relative
        // to the SELECTED week.
        $this->seedShopifyDay($brand->id, $defaultStart->toDateString(), 100, 20, 2);
        $this->seedShopifyDay($brand->id, $selectedStart->toDateString(), 50, 10, 1);
        $this->seedShopifyDay($brand->id, $selectedStart->subWeek()->toDateString(), 25, 5, 1);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/weekly?week={$selectedStart->toDateString()}")
            ->assertOk()
            ->assertJsonPath('week.start', $selectedStart->toDateString())
            ->assertJsonPath('week.end', $selectedStart->addDays(6)->toDateString());

        // KPIs compare the SELECTED week against the one before it.
        $this->assertEquals(60.0, $res->json('kpis.totalRevenue.value'));
        $this->assertEquals(30.0, $res->json('kpis.totalRevenue.previous'));

        // Picker: complete weeks back to the earliest synced row's week (4
        // entries: default, -1, -2 [selected], -3 [its comparison]), most
        // recent first.
        $weeks = collect($res->json('availableWeeks'));
        $this->assertCount(4, $weeks);
        $this->assertSame($defaultStart->toDateString(), $weeks[0]['key']);
        $this->assertSame($selectedStart->toDateString(), $weeks[2]['key']);
        $this->assertNotSame('', (string) $weeks[0]['label']);

        // An incomplete week (the current one) is refused → default window.
        $currentMonday = $defaultStart->addWeek()->toDateString();
        $this->getJson("/api/brands/{$brand->slug}/reports/weekly?week={$currentMonday}")
            ->assertOk()
            ->assertJsonPath('week.start', $defaultStart->toDateString());
    }

    public function test_both_reports_degrade_cleanly_on_an_empty_brand(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);

        Sanctum::actingAs($user);

        // Weekly: no data at all → 200, freshness reports nothing synced (the
        // SPA gates on it), KPIs carry no fabricated comparisons.
        $weekly = $this->getJson("/api/brands/{$brand->slug}/reports/weekly")->assertOk();
        $this->assertFalse($weekly->json('freshness.upToDate'));
        $this->assertNull($weekly->json('freshness.lastSynced'));
        $this->assertNull($weekly->json('kpis.blendedRoas.value'));
        $this->assertNull($weekly->json('kpis.aov.value'));
        $this->assertSame([], $weekly->json('campaignMovers'));
        $this->assertSame([], $weekly->json('actions'));
        $this->assertCount(7, $weekly->json('dailySeries'));
        $this->assertNull($weekly->json('dailySeries.0.revenue'));

        // Creatives: no rows on any platform → 200 with an empty platforms list
        // (absent, never €0 blocks) and the no-data freshness contract.
        $creatives = $this->getJson("/api/brands/{$brand->slug}/reports/creatives?period=last7&compare=previous")->assertOk();
        $this->assertSame([], $creatives->json('platforms'));
        $this->assertFalse($creatives->json('freshness.upToDate'));
        $this->assertNull($creatives->json('freshness.lastSynced'));
    }
}
