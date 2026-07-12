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
}
