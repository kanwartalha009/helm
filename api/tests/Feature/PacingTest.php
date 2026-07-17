<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\User;
use App\Services\Rules\Pacing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-2.1 — monthly targets + pacing.
 *
 * The invariant that matters most: pacing counts only COMPLETE days. If it counted
 * today (a day that hasn't finished and hasn't synced), every brand would read
 * "behind" every morning as a pure artefact of the clock — a wrong number that cries
 * wolf daily. Elapsed days and actuals are drawn from the same complete-day set, so
 * they agree by construction.
 */
final class PacingTest extends TestCase
{
    use RefreshDatabase;

    /** Freeze mid-month so "elapsed" is deterministic: 2026-06-11 → yesterday = the 10th. */
    private function freezeMidJune(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-11 09:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function brand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
    }

    private function day(Brand $brand, string $date, float $revenue, bool $complete = true): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $date,
            'total_sales' => $revenue, 'refunds_amount' => 0, 'orders' => 1,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => $complete, 'pulled_at' => now(),
        ]);
    }

    public function test_pacing_math_mid_month(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        BrandTarget::create(['brand_id' => $brand->id, 'month' => '2026-06', 'revenue_target' => 30000]);

        // 10 complete days (June 1–10), 100 revenue each = 1000.
        for ($d = 1; $d <= 10; $d++) {
            $this->day($brand, sprintf('2026-06-%02d', $d), 100);
        }

        $p = app(Pacing::class)->forBrand($brand->fresh());

        $this->assertSame(30, $p['daysInMonth']);
        $this->assertSame(10, $p['completeDays']);
        // expected-by-now = 30000 × (10/30) = 10000. Actual 1000 → behind by 9000.
        $this->assertEqualsWithDelta(10000.0, $p['revenue']['expectedNow'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $p['revenue']['actual'], 0.001);
        $this->assertEqualsWithDelta(-9000.0, $p['revenue']['delta'], 0.001);
        $this->assertSame('behind', $p['revenue']['status']);
    }

    public function test_on_pace_when_actual_matches_the_pace_line(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        BrandTarget::create(['brand_id' => $brand->id, 'month' => '2026-06', 'revenue_target' => 30000]);

        // 10 complete days at 1000/day = 10000 = exactly the pace line.
        for ($d = 1; $d <= 10; $d++) {
            $this->day($brand, sprintf('2026-06-%02d', $d), 1000);
        }

        $p = app(Pacing::class)->forBrand($brand->fresh());
        $this->assertSame('on_pace', $p['revenue']['status']);
        $this->assertEqualsWithDelta(0.0, $p['revenue']['delta'], 0.001);
        $this->assertEqualsWithDelta(33.3, $p['revenue']['pctOfTarget'], 0.1);
    }

    public function test_incomplete_days_never_make_a_brand_look_behind(): void
    {
        // THE invariant. A brand that is exactly on pace across its complete days must
        // NOT be dragged "behind" by a day that simply hasn't finished syncing.
        $this->freezeMidJune();
        $brand = $this->brand();
        BrandTarget::create(['brand_id' => $brand->id, 'month' => '2026-06', 'revenue_target' => 30000]);

        for ($d = 1; $d <= 10; $d++) {
            $this->day($brand, sprintf('2026-06-%02d', $d), 1000);
        }
        // June 10 also has an INCOMPLETE row for another platform-day scenario: add an
        // incomplete Shopify day that would, if counted, add an 11th elapsed day with
        // ~no revenue and tip the brand into "behind".
        DB::table('daily_metrics')->where('brand_id', $brand->id)->where('date', '2026-06-10')->update(['is_complete' => true]);
        $this->day($brand, '2026-06-11', 0, complete: false);

        $p = app(Pacing::class)->forBrand($brand->fresh());

        $this->assertSame(10, $p['completeDays']);       // the incomplete day is not elapsed
        $this->assertEqualsWithDelta(10000.0, $p['revenue']['actual'], 0.001);  // nor counted in actuals
        $this->assertSame('on_pace', $p['revenue']['status']);
    }

    public function test_no_target_means_no_pacing_never_an_invented_goal(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        $this->day($brand, '2026-06-01', 500);

        $this->assertNull(app(Pacing::class)->forBrand($brand->fresh()));

        // A target with ONLY a revenue figure paces revenue and leaves spend null.
        BrandTarget::create(['brand_id' => $brand->id, 'month' => '2026-06', 'revenue_target' => 1000]);
        $p = app(Pacing::class)->forBrand($brand->fresh());
        $this->assertNotNull($p['revenue']);
        $this->assertNull($p['spend']);   // unset cap → null, never 0
        $this->assertNull($p['roas']);
    }

    public function test_zero_complete_days_claims_no_status(): void
    {
        // Nothing synced yet this month: we have measured nothing, so we assert nothing.
        $this->freezeMidJune();
        $brand = $this->brand();
        BrandTarget::create(['brand_id' => $brand->id, 'month' => '2026-06', 'revenue_target' => 30000]);

        $p = app(Pacing::class)->forBrand($brand->fresh());
        $this->assertSame(0, $p['completeDays']);
        $this->assertSame('unknown', $p['revenue']['status']);
    }

    public function test_targets_crud_and_rbac(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();

        // team_member attached to the brand CAN read, but must not SET a target.
        $tm = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);
        $this->getJson("/api/brands/{$brand->slug}/targets?month=2026-06")->assertOk();
        $this->putJson("/api/brands/{$brand->slug}/targets", ['month' => '2026-06', 'revenue_target' => 1000])
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $this->putJson("/api/brands/{$brand->slug}/targets", ['month' => '2026-06', 'revenue_target' => 30000, 'mer_target' => 3])
            ->assertCreated();

        $res = $this->getJson("/api/brands/{$brand->slug}/targets?month=2026-06")->assertOk()->json();
        $this->assertEqualsWithDelta(30000.0, (float) $res['target']['revenueTarget'], 0.001);
        $this->assertNotNull($res['pacing']);

        // Re-saving the same month updates rather than duplicating (unique brand+month).
        $this->putJson("/api/brands/{$brand->slug}/targets", ['month' => '2026-06', 'revenue_target' => 40000])->assertCreated();
        $this->assertSame(1, BrandTarget::where('brand_id', $brand->id)->count());
    }

    public function test_exact_read_returns_the_scopes_own_row_without_the_standing_default_fallback(): void
    {
        // Kanwar, 2026-07-17 — per-month goals. The goals drawer must tell an
        // INHERITED standing default apart from a real per-month override, so a
        // number set "for July" (stored as the standing default) is never
        // mistaken for May's own goal in the editor. `exact` = no fallback.
        $this->freezeMidJune();
        $brand = $this->brand();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        // A standing default (what the old UI could only write) + one real
        // June override. May has NO override of its own.
        BrandTarget::create(['brand_id' => $brand->id, 'month' => null,      'revenue_target' => 30000]);
        BrandTarget::create(['brand_id' => $brand->id, 'month' => '2026-06', 'revenue_target' => 60000]);

        // Resolved read (cards): May inherits the standing default.
        $resolvedMay = $this->getJson("/api/brands/{$brand->slug}/targets?month=2026-05")->assertOk()->json();
        $this->assertEqualsWithDelta(30000.0, (float) $resolvedMay['target']['revenueTarget'], 0.001);
        $this->assertTrue($resolvedMay['target']['isStandingDefault']);

        // Exact read (editor): May has no row of its own → null, not the default.
        $exactMay = $this->getJson("/api/brands/{$brand->slug}/targets?month=2026-05&exact=1")->assertOk()->json();
        $this->assertNull($exactMay['target']);

        // Exact read of June returns June's OWN number (not the default).
        $exactJune = $this->getJson("/api/brands/{$brand->slug}/targets?month=2026-06&exact=1")->assertOk()->json();
        $this->assertEqualsWithDelta(60000.0, (float) $exactJune['target']['revenueTarget'], 0.001);
        $this->assertFalse($exactJune['target']['isStandingDefault']);

        // Exact read with NO month returns the standing default itself.
        $exactDefault = $this->getJson("/api/brands/{$brand->slug}/targets?exact=1")->assertOk()->json();
        $this->assertEqualsWithDelta(30000.0, (float) $exactDefault['target']['revenueTarget'], 0.001);
        $this->assertTrue($exactDefault['target']['isStandingDefault']);
    }

    public function test_a_future_month_goal_can_be_set_and_is_read_back_exactly(): void
    {
        // "allow to add future months and previous months goals" — a goal set
        // for a month ahead is stored against that month and read back on its own.
        $this->freezeMidJune();
        $brand = $this->brand();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->putJson("/api/brands/{$brand->slug}/targets", ['month' => '2026-09', 'revenue_target' => 75000])
            ->assertCreated();

        $exact = $this->getJson("/api/brands/{$brand->slug}/targets?month=2026-09&exact=1")->assertOk()->json();
        $this->assertEqualsWithDelta(75000.0, (float) $exact['target']['revenueTarget'], 0.001);
        // A different month with no goal stays empty (no bleed).
        $this->assertNull($this->getJson("/api/brands/{$brand->slug}/targets?month=2026-08&exact=1")->assertOk()->json('target'));
    }

    // ---------------------------------------------------------------------
    // Bosco spec 2026-07-12 §A — standing goals, USD-correct ROAS, "needs €X/day"
    // ---------------------------------------------------------------------

    public function test_standing_goal_applies_to_a_month_with_no_override(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();

        // month = null → the STANDING DEFAULT. This is what the Settings UI writes.
        BrandTarget::create(['brand_id' => $brand->id, 'month' => null, 'revenue_target' => 30000]);

        for ($d = 1; $d <= 10; $d++) {
            $this->day($brand, sprintf('2026-06-%02d', $d), 100);
        }

        $p = app(Pacing::class)->forBrand($brand->fresh());

        $this->assertTrue($p['isStandingDefault']);
        $this->assertEqualsWithDelta(30000.0, $p['revenue']['target'], 0.001);
        // And it applies to ANY month, not just the current one.
        $this->assertTrue(app(Pacing::class)->forBrand($brand->fresh(), '2026-09')['isStandingDefault']);
    }

    public function test_a_month_override_beats_the_standing_goal(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();

        BrandTarget::create(['brand_id' => $brand->id, 'month' => null,      'revenue_target' => 30000]);
        BrandTarget::create(['brand_id' => $brand->id, 'month' => '2026-06', 'revenue_target' => 60000]);

        $p = app(Pacing::class)->forBrand($brand->fresh());
        $this->assertFalse($p['isStandingDefault']);
        $this->assertEqualsWithDelta(60000.0, $p['revenue']['target'], 0.001);   // June's own number

        // July has no override → it falls back to the standing goal.
        $july = app(Pacing::class)->forBrand($brand->fresh(), '2026-07');
        $this->assertTrue($july['isStandingDefault']);
        $this->assertEqualsWithDelta(30000.0, $july['revenue']['target'], 0.001);
    }

    public function test_a_brand_can_only_ever_hold_one_standing_goal(): void
    {
        // Spec §A.1 says `unique (brand_id, month)`, but on MySQL NULLs are DISTINCT in a
        // unique index — that constraint would happily admit fifty standing goals and
        // pacing would pick one at random. The month_key generated column (D-025) is what
        // actually enforces this. Saving twice must UPDATE, never duplicate.
        $this->freezeMidJune();
        $brand = $this->brand();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->putJson("/api/brands/{$brand->slug}/targets", ['revenue_target' => 30000])->assertCreated();
        $this->putJson("/api/brands/{$brand->slug}/targets", ['revenue_target' => 45000])->assertCreated();

        $standing = BrandTarget::where('brand_id', $brand->id)->whereNull('month')->get();
        $this->assertCount(1, $standing);
        $this->assertEqualsWithDelta(45000.0, (float) $standing->first()->revenue_target, 0.001);
    }

    public function test_roas_is_computed_in_usd_not_native_over_native(): void
    {
        // A brand booking revenue and spend in different currencies must not get a
        // ratio of two incomparable numbers. Same fx-snapshot math as the dashboard.
        $this->freezeMidJune();
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => 'UTC', 'status' => 'active']);
        BrandTarget::create(['brand_id' => $brand->id, 'month' => null, 'roas_target' => 4.0]);

        // Revenue: 10 × 100 native, fx 1.1 → 1000 native / 1100 USD.
        for ($d = 1; $d <= 10; $d++) {
            DB::table('daily_metrics')->insert([
                'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => sprintf('2026-06-%02d', $d),
                'total_sales' => 100, 'refunds_amount' => 0, 'orders' => 1,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.1, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        }
        // Spend: 100 native, fx 2.0 → 200 USD.
        DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'meta', 'date' => '2026-06-05',
            'spend' => 100, 'currency' => 'EUR', 'fx_rate_to_usd' => 2.0,
            'is_complete' => true, 'pulled_at' => now(),
        ]);

        $p = app(Pacing::class)->forBrand($brand->fresh());

        // native ÷ native would read 10.0× — a fiction. USD-correct is 1100 ÷ 200 = 5.5×.
        $this->assertEqualsWithDelta(5.5, $p['roas']['actual'], 0.001);
        $this->assertSame('on_pace', $p['roas']['status']);   // 5.5 ≥ 4.0
    }

    public function test_roas_with_no_ad_spend_is_null_never_zero(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        BrandTarget::create(['brand_id' => $brand->id, 'month' => null, 'roas_target' => 3.0]);
        $this->day($brand, '2026-06-01', 500);

        $p = app(Pacing::class)->forBrand($brand->fresh());

        // No spend → no ratio EXISTS. Rendering 0× would say "you failed"; the truth is
        // "you didn't run ads". The card shows "—".
        $this->assertNull($p['roas']['actual']);
        $this->assertSame('unknown', $p['roas']['status']);
    }

    public function test_needed_per_day_closes_the_gap_over_the_remaining_days(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        BrandTarget::create(['brand_id' => $brand->id, 'month' => null, 'revenue_target' => 30000]);

        for ($d = 1; $d <= 10; $d++) {
            $this->day($brand, sprintf('2026-06-%02d', $d), 100);   // 1000 booked
        }

        $p = app(Pacing::class)->forBrand($brand->fresh());

        $this->assertSame(20, $p['remainingDays']);                          // 30 − 10
        $this->assertEqualsWithDelta(1450.0, $p['neededPerDay'], 0.001);     // (30000 − 1000) ÷ 20
    }

    public function test_needed_per_day_is_null_once_the_goal_is_already_hit(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        BrandTarget::create(['brand_id' => $brand->id, 'month' => null, 'revenue_target' => 5000]);

        for ($d = 1; $d <= 10; $d++) {
            $this->day($brand, sprintf('2026-06-%02d', $d), 1000);   // 10000 > 5000
        }

        $p = app(Pacing::class)->forBrand($brand->fresh());

        // Goal beaten — the gap is 0, not negative. "Needs −250/day" is nonsense.
        $this->assertEqualsWithDelta(0.0, $p['neededPerDay'], 0.001);
        $this->assertSame('on_pace', $p['revenue']['status']);
    }

    public function test_an_absurd_roas_target_is_rejected_as_a_typo(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->putJson("/api/brands/{$brand->slug}/targets", ['roas_target' => 300])
            ->assertStatus(422);   // 300× is a decimal slip, not a goal (§A.2 ceiling)

        $this->putJson("/api/brands/{$brand->slug}/targets", ['roas_target' => 3.5])->assertCreated();
    }

    public function test_clearing_the_standing_goal_removes_the_cards(): void
    {
        $this->freezeMidJune();
        $brand = $this->brand();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->putJson("/api/brands/{$brand->slug}/targets", ['revenue_target' => 30000])->assertCreated();
        $this->assertNotNull($this->getJson("/api/brands/{$brand->slug}/targets")->json('pacing'));

        $this->deleteJson("/api/brands/{$brand->slug}/targets/__default")->assertOk();

        // Goal gone → pacing null → the Overview cards render nothing at all. Not a 0% bar:
        // a brand with no goal is not a brand failing its goal.
        $this->assertNull($this->getJson("/api/brands/{$brand->slug}/targets")->json('pacing'));
        $this->assertNull($this->getJson("/api/brands/{$brand->slug}/targets")->json('target'));
    }
}
