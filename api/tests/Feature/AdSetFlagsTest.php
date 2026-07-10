<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Services\Rules\AdSetFlags;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdSetFlags engine + drawer endpoint (spec §4 Phase 4). Each flag is exercised
 * at/above its threshold, the min-evidence gate is proven (performance flags stay
 * silent under $50 spend; status-based ones still fire), and the endpoint's shape
 * + brand RBAC are checked. Rows are seeded date-only per guardrail 8; USD rows
 * with fx 1.0 so spend == spendUsd.
 */
final class AdSetFlagsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $o */
    private function row(int $brandId, array $o = []): array
    {
        return array_merge([
            'brand_id' => $brandId, 'platform' => 'meta', 'date' => '2026-06-01',
            'ad_set_id' => 'AS1', 'ad_set_name' => 'Prospecting', 'campaign_id' => 'C1',
            'entity_kind' => 'ad_set', 'status' => 'ACTIVE', 'learning_status' => null,
            'daily_budget' => null, 'lifetime_budget' => null, 'spend' => 0, 'impressions' => 0,
            'clicks' => 0, 'reach' => null, 'frequency' => null, 'conversions' => 0,
            'conversion_value' => 0, 'search_impression_share' => null, 'search_budget_lost_is' => null,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true,
            'pulled_at' => '2026-06-08 00:00:00',
        ], $o);
    }

    /** @param list<array<string, mixed>> $rows */
    private function seedAdSetRows(array $rows): void
    {
        DB::table('ad_set_daily_metrics')->insert($rows);
    }

    /** @return list<string> flag keys for one ad set */
    private function flagsFor(Brand $brand, string $platform = 'meta', string $adSetId = 'AS1'): array
    {
        $res = app(AdSetFlags::class)->forCampaign(
            $brand, $platform, 'C1',
            CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'),
        );
        foreach ($res['rows'] as $r) {
            if ($r['adSetId'] === $adSetId) {
                return array_column($r['flags'], 'key');
            }
        }

        return [];
    }

    public function test_no_purchase_kill_fires_after_three_days_and_min_evidence(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']); // no target CPA → day-based path
        $this->seedAdSetRows([
            $this->row($brand->id, ['date' => '2026-06-01', 'spend' => 20]),
            $this->row($brand->id, ['date' => '2026-06-02', 'spend' => 20]),
            $this->row($brand->id, ['date' => '2026-06-03', 'spend' => 20]), // $60 ≥ $50, 3 days, 0 conv
        ]);

        $this->assertContains('no_purchase_kill', $this->flagsFor($brand));
    }

    public function test_zero_purchase_below_evidence_stays_silent(): void
    {
        // $40 total (< $50 min evidence), zero purchases → NO flag (gate holds).
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAdSetRows([
            $this->row($brand->id, ['date' => '2026-06-01', 'spend' => 20]),
            $this->row($brand->id, ['date' => '2026-06-02', 'spend' => 20]),
        ]);

        $this->assertSame([], $this->flagsFor($brand));
    }

    public function test_below_breakeven_needs_margin(): void
    {
        // margin 50% → breakeven 2.0×. ROAS 1.5× on $100 spend → below breakeven.
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => 50]);
        $this->seedAdSetRows([
            $this->row($brand->id, ['spend' => 100, 'conversions' => 3, 'conversion_value' => 150]),
        ]);

        $this->assertContains('below_breakeven', $this->flagsFor($brand));

        // Same numbers, no margin set → breakeven unknown → flag cannot fire.
        $noMargin = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => null]);
        $this->seedAdSetRows([
            $this->row($noMargin->id, ['spend' => 100, 'conversions' => 3, 'conversion_value' => 150]),
        ]);
        $this->assertNotContains('below_breakeven', $this->flagsFor($noMargin));
    }

    public function test_high_frequency_meta_only(): void
    {
        // impressions 8000 / reach 2000 = freq 4.0 ≥ 4.0; conv>0 so no kill.
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAdSetRows([
            $this->row($brand->id, ['spend' => 80, 'impressions' => 8000, 'reach' => 2000, 'conversions' => 2, 'conversion_value' => 200]),
        ]);

        $this->assertContains('high_frequency', $this->flagsFor($brand));
    }

    public function test_low_ctr_info(): void
    {
        // CTR 0.25% (< 0.5%) with 2000 impressions; no margin so below_breakeven can't fire.
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'gross_margin_pct' => null]);
        $this->seedAdSetRows([
            $this->row($brand->id, ['spend' => 60, 'impressions' => 2000, 'clicks' => 5, 'conversions' => 2, 'conversion_value' => 500]),
        ]);

        $this->assertContains('low_ctr', $this->flagsFor($brand));
    }

    public function test_learning_limited_fires_below_evidence(): void
    {
        // Status-based → exempt from the evidence gate: $10 spend still flags.
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAdSetRows([
            $this->row($brand->id, ['spend' => 10, 'conversions' => 1, 'conversion_value' => 30, 'learning_status' => 'LEARNING_LIMITED']),
        ]);

        $keys = $this->flagsFor($brand);
        $this->assertContains('learning_limited', $keys);
        $this->assertNotContains('no_purchase_kill', $keys); // conv>0
    }

    public function test_google_budget_starved_from_impression_share(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->seedAdSetRows([
            $this->row($brand->id, ['platform' => 'google', 'entity_kind' => 'ad_set', 'status' => 'ENABLED', 'spend' => 30, 'conversions' => 1, 'conversion_value' => 60, 'search_budget_lost_is' => 0.2]),
        ]);

        $this->assertContains('budget_starved', $this->flagsFor($brand, 'google'));
    }

    public function test_endpoint_shape_and_brand_rbac(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $day   = CarbonImmutable::now()->subDay()->toDateString();
        $this->seedAdSetRows([
            $this->row($brand->id, ['date' => $day, 'spend' => 120, 'impressions' => 9000, 'reach' => 1500, 'conversions' => 0, 'pulled_at' => $day . ' 06:00:00']),
        ]);

        // Unassigned non-admin → 404, not 403: the brand-visibility scope hides
        // brands a user is not attached to (existence is never revealed). 403 is
        // reserved for role-gated ACTIONS on brands the user CAN see — cf. the
        // attach-then-403 pattern in DataCoverageTest.
        $outsider = User::factory()->create(['role' => 'team_member']);
        Sanctum::actingAs($outsider);
        $this->getJson("/api/brands/{$brand->slug}/ads/campaigns/C1/adsets?platform=meta&period=last30")
            ->assertNotFound();

        // Master admin → 200 with the documented shape + a flag on the seeded set.
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->getJson("/api/brands/{$brand->slug}/ads/campaigns/C1/adsets?platform=meta&period=last30")
            ->assertOk()
            ->assertJsonStructure([
                'platform', 'campaignId', 'period' => ['start', 'end'], 'asOf',
                'adSets' => [['adSetId', 'name', 'spend', 'roas', 'frequency', 'flags', 'asOf']],
            ])
            ->json();

        $this->assertSame('meta', $res['platform']);
        $this->assertNotEmpty($res['adSets'][0]['flags']);

        // Unknown campaign id → 404, not an empty shell.
        $this->getJson("/api/brands/{$brand->slug}/ads/campaigns/NOPE/adsets?platform=meta")
            ->assertNotFound();
    }
}
