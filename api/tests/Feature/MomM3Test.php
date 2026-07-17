<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M3 (monthly-report-v2-mom.md §M3 — Meta mechanics sections): S13 (audience
 * mix, brand-level per the documented schema deviation), S14 (placement mix +
 * vertical-placement goal chip), S15 (gender mix), S18 (Klaviyo attribution —
 * built with REAL data this pass, correcting the spec's stale "GO-1 pending"
 * premise; GO-1 shipped 2026-07-12, before this session).
 *
 * CORRECTED (M5 S1/HeatTable pass, 2026-07-15) — this test's own
 * "should not be ready" assertion for S16/S17 had gone stale: S17 was
 * unblocked in the M2/M3 final slice (well before this correction), and S16
 * is unblocked as of this pass (see SAwarenessCountrySection + the objective
 * column). Both are registered and ready now — see MomS16Test.php for S16's
 * own build-logic coverage.
 */
class MomM3Test extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfMonth()->subMonth();
    }

    private function makeBrand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);
    }

    private function seedBreakdown(int $brandId, string $date, string $type, string $segKey, array $cols, ?string $label = null): void
    {
        DB::table('meta_breakdown_daily')->insert(array_merge([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => $date,
            'breakdown_type' => $type, 'segment_key' => $segKey, 'segment_label' => $label,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ], $cols));
    }

    private function seedMetaSpend(int $brandId, string $date, float $spend): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'spend' => $spend,
        ]);
    }

    public function test_mom_shell_reports_the_four_new_m3_sections_ready_and_the_two_gaps_not_ready(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $this->seedMetaSpend($brand->id, $this->monthStart()->addDays(2)->toDateString(), 100);

        Sanctum::actingAs($user);
        $sections = collect($this->getJson("/api/brands/{$brand->slug}/reports/mom")->assertOk()->json('sections'))->keyBy('key');

        foreach (['S13', 'S14', 'S15', 'S16', 'S17', 'S18'] as $k) {
            $this->assertTrue($sections[$k]['ready'], "{$k} should be ready");
        }
    }

    public function test_s13_audience_mix_computes_existing_pct_and_alarm_against_benchmark(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        // Total Meta spend for the month: 1000. Existing segment: 200 -> 20% > 15% benchmark -> alarm.
        $this->seedMetaSpend($brand->id, $date, 1000);
        $this->seedBreakdown($brand->id, $date, 'audience', 'prospecting', ['spend' => 700]);
        $this->seedBreakdown($brand->id, $date, 'audience', 'existing', ['spend' => 200]);
        $this->seedBreakdown($brand->id, $date, 'audience', 'engaged', ['spend' => 100]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S13?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEquals(20.0, $res->json('existingPct')); // existing 200 / window total 1000
        $this->assertTrue($res->json('alarm'));
        $this->assertArrayHasKey('perCampaign', $res->json('unavailable'));
        $this->assertArrayHasKey('aov', $res->json('unavailable'));
        // Now a month-by-month matrix: the Existing segment is a row with a spend total.
        $existing = collect($res->json('rows'))->firstWhere('key', 'existing');
        $this->assertEquals(200.0, $existing['spend']);
        $this->assertIsArray($res->json('months'));
    }

    public function test_s14_placement_mix_computes_vertical_pct_and_goal_chip(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        // 900 total; 800 in a "Stories" placement (~89% vertical, above the 80% goal).
        $this->seedBreakdown($brand->id, $date, 'placement', 'instagram_stories', ['spend' => 800, 'impressions' => 10000, 'clicks' => 200], 'Instagram Stories');
        $this->seedBreakdown($brand->id, $date, 'placement', 'facebook_feed', ['spend' => 100, 'impressions' => 2000, 'clicks' => 20], 'Facebook Feed');

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S14?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEqualsWithDelta(88.9, $res->json('verticalPct.value'), 0.2);
        $this->assertTrue($res->json('goalHit'));
        $this->assertEquals(80.0, $res->json('goal'));
        // Kanwar, 2026-07-16: detailed metric columns per placement row.
        $stories = collect($res->json('rows'))->firstWhere('key', 'instagram_stories');
        $this->assertEqualsWithDelta(2.0, $stories['ctr'], 0.01);  // 200/10000*100
        $this->assertEqualsWithDelta(80.0, $stories['cpm'], 0.01); // 800/10000*1000
        $this->assertEqualsWithDelta(88.9, $stories['sharePct'], 0.2); // 800/900
    }

    public function test_s15_gender_mix_folds_age_gender_into_detailed_metric_rows(): void
    {
        // Kanwar, 2026-07-16 — S15 is now a detailed per-gender metrics table
        // (Cost/Reach/Freq/Clicks/CTR/CPM/Purch/ROAS/CPA/Share), age_gender folded
        // to Male/Female/Unknown with every metric summed (not just spend).
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        $this->seedBreakdown($brand->id, $date, 'age_gender', '25-34 · female', ['spend' => 300, 'impressions' => 10000, 'clicks' => 100, 'conversions' => 10, 'conversion_value' => 900]);
        $this->seedBreakdown($brand->id, $date, 'age_gender', '35-44 · male', ['spend' => 100, 'impressions' => 5000, 'clicks' => 25, 'conversions' => 4, 'conversion_value' => 400]);
        $this->seedBreakdown($brand->id, $date, 'age_gender', 'unknown', ['spend' => 50]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S15?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $rows = collect($res->json('rows'))->keyBy('key');
        $this->assertEquals(300.0, $rows['female']['spend']);
        $this->assertEquals(100.0, $rows['male']['spend']);
        $this->assertEqualsWithDelta(1.0, $rows['female']['ctr'], 0.01);   // 100/10000*100
        $this->assertEqualsWithDelta(3.0, $rows['female']['roas'], 0.01);  // 900/300
        $this->assertEqualsWithDelta(30.0, $rows['female']['cpa'], 0.01);  // 300/10 purchases
        $this->assertEqualsWithDelta(66.7, $rows['female']['sharePct'], 0.2); // 300 / 450
    }

    public function test_s15_always_shows_the_male_row_even_when_only_female_has_spend(): void
    {
        // Kanwar, 2026-07-17 — "even if male is zero show male row with zero".
        // With ONLY female age_gender data, both known genders must still render
        // (male at an honest €0), so the client never sees a one-sided table.
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        $this->seedBreakdown($brand->id, $date, 'age_gender', '25-34 · female', ['spend' => 300, 'impressions' => 10000, 'clicks' => 100, 'conversions' => 10, 'conversion_value' => 900]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S15?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $rows = collect($res->json('rows'))->keyBy('key');
        $this->assertArrayHasKey('male', $rows, 'Male row must always be present');
        $this->assertEquals(0.0, $rows['male']['spend']);
        $this->assertEquals(0, $rows['male']['clicks']);
        $this->assertNull($rows['male']['roas']); // no spend -> honestly blank, not a fabricated rate
        $this->assertEquals(300.0, $rows['female']['spend']);
    }

    public function test_s15_offers_tiktok_only_when_tiktok_is_connected_or_has_breakdown_data(): void
    {
        // Kanwar, 2026-07-17 — "if tiktok is not connected with brand then only
        // show meta". availablePlatforms drives the frontend toggle: meta always,
        // tiktok only when there's an active connection OR real tiktok breakdown.
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        $this->seedBreakdown($brand->id, $date, 'age_gender', '25-34 · female', ['spend' => 300, 'impressions' => 10000, 'clicks' => 100]);

        Sanctum::actingAs($user);
        $metaOnly = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S15?month={$month->format('Y-m')}")
            ->assertOk()->json('availablePlatforms');
        $this->assertSame(['meta'], $metaOnly);

        // A real tiktok age_gender row for the window -> tiktok becomes offerable.
        DB::table('meta_breakdown_daily')->insert([
            'brand_id' => $brand->id, 'platform' => 'tiktok', 'date' => $date,
            'breakdown_type' => 'age_gender', 'segment_key' => '25-34 · female', 'segment_label' => null,
            'spend' => 120, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        $withTiktok = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S15?month={$month->format('Y-m')}")
            ->assertOk()->json('availablePlatforms');
        $this->assertContains('tiktok', $withTiktok);
    }

    public function test_s14_offers_tiktok_when_a_tiktok_connection_is_active_even_with_no_data(): void
    {
        // The connected-but-zero-data case: an active tiktok connection alone
        // makes the toggle offer tiktok (mirrors S1's connected-OR-has-data rule).
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        $this->seedBreakdown($brand->id, $date, 'placement', 'facebook_feed', ['spend' => 100, 'impressions' => 2000, 'clicks' => 20], 'Facebook Feed');
        (new PlatformConnection())->forceFill([
            'brand_id' => $brand->id, 'platform' => 'tiktok',
            'external_id' => "acc-{$brand->id}-tiktok", 'status' => 'active', 'credentials' => ['k' => 'v'],
        ])->save();

        Sanctum::actingAs($user);
        $platforms = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S14?month={$month->format('Y-m')}")
            ->assertOk()->json('availablePlatforms');
        $this->assertContains('tiktok', $platforms);
    }

    public function test_s18_klaviyo_needs_source_then_real_attribution_never_summed_into_store_revenue(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();

        Sanctum::actingAs($user);
        // UPDATED (end-to-end completion, 2026-07-15): with NO Klaviyo key
        // configured, S18 is HIDDEN entirely (Kanwar: "if klaviyo not
        // connected don't show") — not a Connect-Klaviyo placeholder.
        $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S18?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'hidden')->assertJsonPath('hidden', true);

        // Connect Klaviyo (a private key exists) but no data yet -> needs_source.
        app(\App\Services\PlatformCredentialService::class)->set('klaviyo', 'private_key', 'pk_test', brandId: (int) $brand->id);
        $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S18?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'needs_source');

        $date = $month->addDays(3)->toDateString();
        // Store revenue 1000 (D-005), Klaviyo-attributed revenue 300 (flow) + 100 (campaign) = 400 -> 40% share.
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            'total_sales' => 1000, 'refunds_amount' => 0,
        ]);
        DB::table('email_daily_metrics')->insert([
            'brand_id' => $brand->id, 'date' => $date, 'source' => 'flow', 'source_id' => 'f1', 'source_name' => 'Welcome flow',
            'conversion_value' => 300, 'conversions' => 3, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
        DB::table('email_daily_metrics')->insert([
            'brand_id' => $brand->id, 'date' => $date, 'source' => 'campaign', 'source_id' => 'c1', 'source_name' => 'July promo',
            'conversion_value' => 100, 'conversions' => 1, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S18?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEquals(400.0, $res->json('revenue'));
        $this->assertEquals(40.0, $res->json('shareOfStore.value'));
        $this->assertEquals(300.0, $res->json('splits.flow.revenue'));
        $this->assertEquals(100.0, $res->json('splits.campaign.revenue'));
        $this->assertNotEmpty($res->json('honestyBox'));
        $this->assertArrayHasKey('listGrowth', $res->json('unavailable'));

        // Honesty law (§0.1): shareOfStore is a RATIO of two independently
        // measured numbers (400/1000), not an additive split — 'revenue' stays
        // exactly the Klaviyo-attributed figure, never store+email combined.
        $this->assertEquals(400.0, $res->json('revenue'));
    }
}
