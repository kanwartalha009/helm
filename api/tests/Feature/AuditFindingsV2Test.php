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
 * Phase 2 store-audit cards v2 (spec §4 Phase 2). Revenue trend, tracking
 * reconciliation, breakeven (margin-gated) and partial-window suppression.
 * last7 window keeps the relative-date seeding light; dates are seeded with
 * date-only strings and read via whereBetween (guardrail 8).
 */
final class AuditFindingsV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->masterAdmin()->create());
    }

    private function yesterday(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subDay()->startOfDay();
    }

    private function seedShopify(int $brandId, string $date, float $revenue, float $refunds = 0, int $orders = 1, bool $complete = true): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id'       => $brandId,
            'platform'       => 'shopify',
            'date'           => $date,
            'total_sales'    => $revenue - $refunds,
            'net_sales'      => $revenue - $refunds,
            'refunds_amount' => $refunds,
            'orders'         => $orders,
            'currency'       => 'USD',
            'fx_rate_to_usd' => 1.0,
            'is_complete'    => $complete,
            'pulled_at'      => now(),
        ]);
    }

    private function seedAd(int $brandId, string $date, float $spend, float $convValue): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id'         => $brandId,
            'platform'         => 'meta',
            'date'             => $date,
            'spend'            => $spend,
            'conversion_value' => $convValue,
            'conversions'      => 1,
            'currency'         => 'USD',
            'fx_rate_to_usd'   => 1.0,
            'is_complete'      => true,
            'pulled_at'        => now(),
        ]);
    }

    /** @return array{0: list<string>, 1: list<string>} [currentWindowDates, priorWindowDates] for last7. */
    private function windows(): array
    {
        $y = $this->yesterday();
        $cur = [];
        for ($i = 0; $i <= 6; $i++) {
            $cur[] = $y->subDays($i)->toDateString();
        }
        $prior = [];
        for ($i = 7; $i <= 13; $i++) {
            $prior[] = $y->subDays($i)->toDateString();
        }

        return [$cur, $prior];
    }

    private function findings(Brand $brand): array
    {
        return $this->getJson("/api/brands/{$brand->slug}/audit-findings?period=last7")
            ->assertOk()
            ->json('findings');
    }

    public function test_revenue_drop_raises_a_revenue_card(): void
    {
        $brand = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'USD']);
        [$cur, $prior] = $this->windows();
        foreach ($cur as $d) {
            $this->seedShopify($brand->id, $d, 100);   // 700 total
        }
        foreach ($prior as $d) {
            $this->seedShopify($brand->id, $d, 300);   // 2100 prior → −66% ⇒ critical
        }

        $f = collect($this->findings($brand));
        $this->assertTrue($f->contains(fn ($x) => $x['area'] === 'revenue' && str_contains($x['title'], 'Revenue down')));
    }

    public function test_partial_window_suppresses_the_revenue_trend(): void
    {
        $brand = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'USD']);
        [$cur, $prior] = $this->windows();
        // Only 3 of 7 current days complete ⇒ no revenue verdict.
        foreach (array_slice($cur, 0, 3) as $d) {
            $this->seedShopify($brand->id, $d, 100);
        }
        foreach ($prior as $d) {
            $this->seedShopify($brand->id, $d, 300);
        }

        $f = collect($this->findings($brand));
        $this->assertFalse($f->contains(fn ($x) => str_contains($x['title'], 'Revenue down')));
    }

    public function test_tracking_overcount_warns(): void
    {
        $brand = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'USD']);
        [$cur] = $this->windows();
        foreach ($cur as $d) {
            $this->seedShopify($brand->id, $d, 100); // store 700
            $this->seedAd($brand->id, $d, 20, 200);  // ad conv value 1400 ≫ 700×1.1
        }

        $f = collect($this->findings($brand));
        $this->assertTrue($f->contains(fn ($x) => $x['area'] === 'tracking' && $x['severity'] === 'warn'));
    }

    public function test_breakeven_card_only_when_margin_set(): void
    {
        [$cur] = $this->windows();

        // No margin → no breakeven card even when clearly unprofitable.
        $noMargin = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'USD', 'gross_margin_pct' => null]);
        foreach ($cur as $d) {
            $this->seedAd($noMargin->id, $d, 100, 50); // blended 0.5
        }
        $this->assertFalse(collect($this->findings($noMargin))->contains(fn ($x) => str_contains($x['title'], 'breakeven')));

        // Margin 50 (breakeven 2.0×), blended 0.5 < 2.0 ⇒ critical breakeven card.
        $withMargin = Brand::factory()->create(['timezone' => 'UTC', 'base_currency' => 'USD', 'gross_margin_pct' => 50]);
        foreach ($cur as $d) {
            $this->seedAd($withMargin->id, $d, 100, 50);
        }
        $this->assertTrue(collect($this->findings($withMargin))->contains(fn ($x) => $x['area'] === 'ads' && str_contains($x['title'], 'below breakeven')));
    }
}
