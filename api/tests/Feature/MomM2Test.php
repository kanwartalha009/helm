<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M2 (monthly-report-v2-mom.md §M2): the `mom` report SHELL (MomReport::build,
 * no section data), the section-streamed endpoints (MomSectionController), and
 * the two sections this pass actually built (S-EX, S-GOALS).
 *
 * Confirms v1 stays untouched (REV2 R7): 'monthly' is unaffected by 'mom'
 * existing alongside it in the registry.
 */
class MomM2Test extends TestCase
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

    private function seedDaily(int $brandId, string $platform, string $date, array $cols, float $fx = 1.0): void
    {
        DB::table('daily_metrics')->insert(array_merge([
            'brand_id' => $brandId, 'platform' => $platform, 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => $fx, 'is_complete' => true, 'pulled_at' => now(),
        ], $cols));
    }

    public function test_mom_report_shell_carries_the_resolved_section_manifest_with_ready_flags(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $monthEnd = $this->monthStart()->endOfMonth();
        $this->seedDaily($brand->id, 'shopify', $monthEnd->toDateString(), ['total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 10]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom")
            ->assertOk()
            ->assertJsonPath('reportType', 'mom')
            ->assertJsonStructure(['month', 'availableMonths', 'sections', 'freshness']);

        $sections = collect($res->json('sections'))->keyBy('key');
        // Code-default catalog order: S-EX first (REV2 R4), then S-GOALS (R5).
        $this->assertSame('S-EX', $res->json('sections.0.key'));
        $this->assertTrue($sections['S-EX']['ready']);
        $this->assertTrue($sections['S-GOALS']['ready']);

        // v1 is untouched and unaffected by mom existing in the registry.
        $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk()->assertJsonPath('reportType', 'monthly');
        $types = collect($this->getJson('/api/reports')->assertOk()->json('reports'))->pluck('key');
        $this->assertTrue($types->contains('monthly'));
        $this->assertTrue($types->contains('mom'));
    }

    public function test_sex_section_computes_d005_revenue_and_compare_month_delta(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $monthStart = $this->monthStart();
        $monthEnd   = $monthStart->endOfMonth();
        $prevStart  = $monthStart->subMonth();

        // Report month: 1000 + 100 refunds = 1100 revenue (D-005), 500 spend -> ROAS 2.2.
        $this->seedDaily($brand->id, 'shopify', $monthEnd->toDateString(), ['total_sales' => 1000, 'refunds_amount' => 100, 'orders' => 10]);
        $this->seedDaily($brand->id, 'meta', $monthStart->addDays(4)->toDateString(), ['spend' => 500]);
        // Compare month (previous): 550 revenue, 250 spend.
        $this->seedDaily($brand->id, 'shopify', $prevStart->addDays(9)->toDateString(), ['total_sales' => 500, 'refunds_amount' => 50, 'orders' => 5]);
        $this->seedDaily($brand->id, 'meta', $prevStart->addDays(9)->toDateString(), ['spend' => 250]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX?month={$monthStart->format('Y-m')}")
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertEquals(1100.0, $res->json('tiles.revenue.value'));
        $this->assertEquals(550.0, $res->json('tiles.revenue.compare'));
        $this->assertEqualsWithDelta(100.0, $res->json('tiles.revenue.deltaPct'), 0.1);
        $this->assertEquals(2.2, $res->json('tiles.blendedRoas.value'));
        $this->assertEquals(110.0, $res->json('tiles.aov.value')); // 1100 / 10 orders
        $this->assertEquals(10, $res->json('tiles.orders.value'));

        // UPDATED (end-to-end completion, 2026-07-15): MER is now a real tile
        // (store revenue ÷ total ad spend = 1100/500 = 2.2, the TruthSpine
        // spine) — no longer an "unavailable" placeholder.
        $this->assertEquals(2.2, $res->json('tiles.mer.value'));

        // Still honestly unavailable on THIS fixture (no Shopify connection →
        // no live customer split; no funnel rows → no sessions), never faked.
        $this->assertArrayHasKey('cac', $res->json('unavailable'));
        $this->assertArrayHasKey('newVsReturningPct', $res->json('unavailable'));
        $this->assertArrayHasKey('sessions', $res->json('unavailable'));

        // Email is OMITTED entirely (not connected → not in tiles OR
        // unavailable) — Kanwar's "if Klaviyo not connected, don't show".
        $this->assertNull($res->json('tiles.emailRevenue'));
        $this->assertArrayNotHasKey('emailRevenue', $res->json('unavailable'));
    }

    public function test_sgoals_section_renders_only_when_a_target_is_set(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $month = $this->monthStart()->format('Y-m');

        Sanctum::actingAs($user);
        // No target at all -> no_data, never a fabricated 0%-of-goal bar.
        $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-GOALS?month={$month}")
            ->assertOk()
            ->assertJsonPath('status', 'no_data');

        BrandTarget::create(['brand_id' => $brand->id, 'month' => null, 'revenue_target' => 1000, 'roas_target' => 3]);
        $this->seedDaily($brand->id, 'shopify', $this->monthStart()->addDays(2)->toDateString(), ['total_sales' => 400, 'refunds_amount' => 0, 'orders' => 4]);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-GOALS?month={$month}")
            ->assertOk()
            ->assertJsonPath('status', 'ok');
        $this->assertEquals(1000.0, $res->json('revenue.target'));
        $this->assertFalse($res->json('revenue.goalHit')); // 400 of 1000
    }

    public function test_unregistered_section_key_degrades_honestly_not_a_404_or_500(): void
    {
        // CORRECTED (M5 S1/HeatTable pass, 2026-07-15): every key in the
        // momreport.php catalog is now registered (S16 was the last gap,
        // closed this pass) — this test now uses a synthetic key that will
        // never be a real section, so it keeps testing the SAME code path
        // (MomSectionRegistry::has() === false) without depending on some
        // section staying permanently unbuilt.
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        Sanctum::actingAs($user);

        $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S999")
            ->assertOk()
            ->assertJsonPath('status', 'not_built_yet');
    }

    public function test_section_commentary_crud_and_rbac(): void
    {
        $brand = $this->makeBrand();
        $month = $this->monthStart()->format('Y-m');

        $tm = User::factory()->create(['role' => 'team_member']);
        $brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);

        $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX/commentary?month={$month}")
            ->assertOk()
            ->assertJsonPath('commentary', null);

        $this->putJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX/commentary", [
            'month' => $month, 'commentary' => 'Strong month', 'todo' => [['text' => 'Follow up with Bosco']],
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $this->putJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX/commentary", [
            'month' => $month, 'commentary' => 'Strong month', 'todo' => [['text' => 'Follow up with Bosco', 'done' => false]],
        ])->assertOk();

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX/commentary?month={$month}")->assertOk();
        $this->assertSame('Strong month', $res->json('commentary'));
        $this->assertSame('Follow up with Bosco', $res->json('todo.0.text'));

        // Saving again for the same brand+month+section UPDATES, never duplicates.
        $this->putJson("/api/brands/{$brand->slug}/reports/mom/sections/S-EX/commentary", [
            'month' => $month, 'commentary' => 'Revised',
        ])->assertOk();
        $this->assertSame(1, DB::table('report_commentaries')->where('brand_id', $brand->id)->count());
    }
}
