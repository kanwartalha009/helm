<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BudgetPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-2.2 — budget planner. A PLAN DOCUMENT: it reads trailing-90d actuals, derives a
 * run-rate, and records what a human intends to spend. It never writes to an ad
 * platform, and there is no code path from it to one.
 *
 * The run-rate honesty rule under test: the rate comes from days that actually HAVE
 * data, not from the calendar. A platform with 10 days of spend must not have that
 * spend divided by 90 — that would understate the run-rate 9× and every plan built on
 * it would be wrong.
 */
final class BudgetPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));
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

    private function connect(Brand $b, string $platform): void
    {
        DB::table('platform_connections')->insert([
            'brand_id' => $b->id, 'platform' => $platform, 'external_id' => "acct_{$platform}",
            'credentials' => '{}', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function spendDay(Brand $b, string $platform, string $date, float $spend, float $value = 0): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $b->id, 'platform' => $platform, 'date' => $date,
            'spend' => $spend, 'conversion_value' => $value,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function plan(Brand $b, string $month = '2026-07'): array
    {
        return $this->getJson("/api/brands/{$b->slug}/budget-plan?month={$month}")->assertOk()->json();
    }

    public function test_run_rate_uses_days_with_data_not_the_calendar(): void
    {
        $brand = $this->brand();
        $this->connect($brand, 'meta');

        // Only 10 days of spend at 100/day = 1000. July has 31 days.
        // Correct run-rate = (1000 / 10) × 31 = 3100.
        // The bug this guards: (1000 / 90) × 31 = 344 — a 9× understatement.
        for ($d = 1; $d <= 10; $d++) {
            $this->spendDay($brand, 'meta', sprintf('2026-06-%02d', $d), 100, 300);
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $row = collect($this->plan($brand)['rows'])->firstWhere('platform', 'meta');

        $this->assertSame(10, $row['days90']);
        $this->assertEqualsWithDelta(1000.0, $row['spend90'], 0.001);
        $this->assertEqualsWithDelta(3100.0, $row['runRateMonth'], 0.001);
        // Platform-REPORTED roas (300×10 / 100×10 = 3.0), labelled as such in the payload note.
        $this->assertEqualsWithDelta(3.0, $row['reportedRoas'], 0.001);
    }

    public function test_delta_is_plan_minus_run_rate(): void
    {
        $brand = $this->brand();
        $this->connect($brand, 'meta');
        for ($d = 1; $d <= 10; $d++) {
            $this->spendDay($brand, 'meta', sprintf('2026-06-%02d', $d), 100);
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        // Plan 4000 against a 3100 run-rate → +900 (+29%).
        $this->putJson("/api/brands/{$brand->slug}/budget-plan", [
            'month' => '2026-07', 'platform' => 'meta', 'planned_spend' => 4000,
        ])->assertCreated();

        $res = $this->plan($brand);
        $row = collect($res['rows'])->firstWhere('platform', 'meta');

        $this->assertEqualsWithDelta(4000.0, $row['plannedSpend'], 0.001);
        $this->assertEqualsWithDelta(900.0, $row['delta'], 0.001);
        $this->assertEqualsWithDelta(29.0, $row['deltaPct'], 0.5);
        $this->assertEqualsWithDelta(4000.0, $res['totals']['plannedSpend'], 0.001);

        // Re-saving the same cell updates rather than duplicating (unique key).
        $this->putJson("/api/brands/{$brand->slug}/budget-plan", [
            'month' => '2026-07', 'platform' => 'meta', 'planned_spend' => 5000,
        ])->assertCreated();
        $this->assertSame(1, BudgetPlan::where('brand_id', $brand->id)->count());
    }

    public function test_no_history_is_null_never_zero_and_unconnected_platforms_are_absent(): void
    {
        $brand = $this->brand();
        $this->connect($brand, 'meta');   // connected but no spend rows at all
        // google/tiktok deliberately NOT connected.

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->plan($brand);

        $platforms = collect($res['rows'])->pluck('platform')->all();
        $this->assertSame(['meta'], $platforms);         // absent, not zero rows

        $meta = collect($res['rows'])->firstWhere('platform', 'meta');
        $this->assertNull($meta['spend90']);             // missing ≠ 0
        $this->assertNull($meta['runRateMonth']);
        $this->assertNull($meta['plannedSpend']);
        $this->assertNull($meta['delta']);
    }

    public function test_planning_is_admin_manager_only_and_never_executes(): void
    {
        $brand = $this->brand();
        $this->connect($brand, 'meta');

        // Attached team_member can READ the plan but cannot set a number.
        $tm = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);
        $this->getJson("/api/brands/{$brand->slug}/budget-plan?month=2026-07")->assertOk();
        $this->putJson("/api/brands/{$brand->slug}/budget-plan", [
            'month' => '2026-07', 'platform' => 'meta', 'planned_spend' => 100,
        ])->assertForbidden();

        // The payload states, on every render, that Helm does not push budgets anywhere.
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->plan($brand);
        $this->assertStringContainsString('does not push budgets', $res['executionNote']);
    }
}
