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
 * M5 addendum (Kanwar, 2026-07-15 — "complete the full mom report") — S16
 * "Thruplay/awareness country concentration", unblocked this pass by the new
 * `objective` column on ad_campaign_daily_metrics + the new
 * 'awareness_country' meta_breakdown_daily axis (CampaignSync::
 * syncMetaAwarenessCountry / meta:backfill-awareness-country).
 *
 * This test seeds meta_breakdown_daily directly (the same "test the section's
 * build logic against already-synced tables" pattern every other mom section
 * test uses, e.g. MomM3Test's S13/S14/S15) rather than exercising the actual
 * Meta API call shape — that part is explicitly flagged unverified in
 * SAwarenessCountrySection's own docblock, same honesty discipline as the S1
 * customer_type probe.
 */
class MomS16Test extends TestCase
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

    private function seedAwarenessCountry(int $brandId, string $date, string $iso2, float $spend): void
    {
        DB::table('meta_breakdown_daily')->insert([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => $date,
            'breakdown_type' => 'awareness_country', 'segment_key' => $iso2, 'segment_label' => $iso2,
            'spend' => $spend, 'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    public function test_s16_needs_source_when_no_awareness_country_data_synced_yet(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        Sanctum::actingAs($user);

        $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S16?month=" . $this->monthStart()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('status', 'needs_source');
    }

    public function test_s16_flags_concentration_alert_when_top_country_exceeds_the_threshold(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        // 700 US + 200 GB + 100 FR = 1000 total. US share = 70% > the 50% [HELM DEFAULT] threshold -> alert.
        $this->seedAwarenessCountry($brand->id, $date, 'US', 700);
        $this->seedAwarenessCountry($brand->id, $date, 'GB', 200);
        $this->seedAwarenessCountry($brand->id, $date, 'FR', 100);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S16?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEquals(1000.0, $res->json('totalSpend.value'));
        $this->assertEquals('US', $res->json('topCountry'));
        $this->assertEquals(70.0, $res->json('topSharePct.value'));
        $this->assertEquals(50.0, $res->json('threshold'));
        $this->assertTrue($res->json('alert'));
        $this->assertCount(3, $res->json('rows'));
        // Sorted spend-desc — US first.
        $this->assertEquals('US', $res->json('rows.0.iso2'));
    }

    public function test_s16_no_alert_when_spend_is_evenly_spread(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $date  = $month->addDays(3)->toDateString();

        // 400/400/200 -> top share 40%, under the 50% threshold.
        $this->seedAwarenessCountry($brand->id, $date, 'US', 400);
        $this->seedAwarenessCountry($brand->id, $date, 'GB', 400);
        $this->seedAwarenessCountry($brand->id, $date, 'FR', 200);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S16?month={$month->format('Y-m')}")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEquals(40.0, $res->json('topSharePct.value'));
        $this->assertFalse($res->json('alert'));
    }

    public function test_s16_reads_a_trailing_window_so_current_month_awareness_still_shows(): void
    {
        // Kanwar, 2026-07-17 — awareness runs sparsely and the useful data is
        // usually in the CURRENT, still-open month, which the month picker can't
        // select. S16 now reads a trailing window ending yesterday, so in-progress
        // awareness spend surfaces even when a completed month is selected.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', self::TZ));
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        // Awareness spend a few days ago — in the CURRENT month (July 2026), which
        // is NOT a selectable complete month.
        $this->seedAwarenessCountry($brand->id, '2026-07-10', 'US', 800);
        $this->seedAwarenessCountry($brand->id, '2026-07-11', 'GB', 200);

        Sanctum::actingAs($user);
        // Selecting the last COMPLETE month (June, which has no awareness data): the
        // section still shows the trailing-window July data instead of empty.
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S16?month=2026-06")
            ->assertOk()->assertJsonPath('status', 'ok');

        $this->assertEquals(1000.0, $res->json('totalSpend.value'));
        $this->assertEquals('US', $res->json('topCountry'));
        $this->assertEquals(80.0, $res->json('topSharePct.value'));
        $this->assertTrue($res->json('window.trailing'));

        CarbonImmutable::setTestNow();
    }

    public function test_mom_shell_reports_s16_ready(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        Sanctum::actingAs($user);

        $sections = collect($this->getJson("/api/brands/{$brand->slug}/reports/mom")->assertOk()->json('sections'))->keyBy('key');
        $this->assertTrue($sections['S16']['ready']);
    }
}
