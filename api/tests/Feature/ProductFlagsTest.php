<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Services\Rules\ProductFlags;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 engine (spec §4 Phase 1). Deterministic ABC grades + underperformer
 * flags. Rows seeded via DB::table with date-only strings and queried with
 * whereBetween (guardrail 8 — sqlite stores cast dates as "Y-m-d 00:00:00", so
 * exact-date equality silently matches nothing).
 */
final class ProductFlagsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $start;
    private CarbonImmutable $end;

    protected function setUp(): void
    {
        parent::setUp();
        $this->start = CarbonImmutable::parse('2026-06-01');
        $this->end   = CarbonImmutable::parse('2026-06-30'); // 30-day window; prior = 2026-05-02..05-31
    }

    private function brand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'USD']);
    }

    /** revenue = total_sales + refunds (D-005); pass the revenue you want + its refund slice. */
    private function seedProduct(int $brandId, string $date, string $title, float $revenue, float $refunds = 0, int $units = 1, int $orders = 1, float $fx = 1.0): void
    {
        DB::table('commerce_daily_metrics')->insert([
            'brand_id'       => $brandId,
            'date'           => $date,
            'dimension_type' => 'product',
            'dimension_key'  => $title,
            'dimension_label' => $title,
            'orders'         => $orders,
            'units'          => $units,
            'net_sales'      => $revenue - $refunds,
            'total_sales'    => $revenue - $refunds,
            'refunds_amount' => $refunds,
            'currency'       => 'USD',
            'fx_rate_to_usd' => $fx,
            'is_complete'    => true,
            'pulled_at'      => now(),
        ]);
    }

    private function seedSnapshot(int $brandId, string $title, int $ending, int $sold, ?float $sellThrough = null, int $windowDays = 90): void
    {
        DB::table('inventory_snapshots')->insert([
            'brand_id'          => $brandId,
            'captured_on'       => '2026-06-30',
            'dimension_type'    => 'product',
            'dimension_key'     => $title,
            'dimension_label'   => $title,
            'ending_units'      => $ending,
            'units_sold'        => $sold,
            'sell_through_rate' => $sellThrough,
            'window_days'       => $windowDays,
        ]);
    }

    /** @return list<string> flag keys for a product. */
    private function flagKeys(array $result, string $key): array
    {
        return array_map(static fn (array $f): string => $f['key'], $result[$key]['flags'] ?? []);
    }

    public function test_abc_needs_ten_products_else_null(): void
    {
        $brand = $this->brand();
        foreach (range(1, 5) as $i) {
            $this->seedProduct($brand->id, '2026-06-10', "P{$i}", 100 * $i);
        }

        $out = app(ProductFlags::class)->forBrand($brand->id, $this->start, $this->end);

        $this->assertNull($out['P1']['abc']); // <10 products with revenue → not graded
    }

    public function test_abc_grades_top_is_a_tail_is_c(): void
    {
        $brand = $this->brand();
        $revs = [1000, 500, 300, 200, 100, 50, 40, 30, 20, 10, 5, 5];
        foreach ($revs as $i => $rev) {
            $this->seedProduct($brand->id, '2026-06-10', 'P' . ($i + 1), (float) $rev);
        }

        $out = app(ProductFlags::class)->forBrand($brand->id, $this->start, $this->end);

        $this->assertSame('A', $out['P1']['abc']);   // top product always A
        $this->assertSame('C', $out['P12']['abc']);  // long tail = C
    }

    public function test_declining_fires_at_threshold_and_floor_suppresses_tiny(): void
    {
        $brand = $this->brand();
        // Big product: prior 1000, current 500 → −50% ≥ 30% ⇒ declining.
        $this->seedProduct($brand->id, '2026-05-15', 'Widget', 1000);
        $this->seedProduct($brand->id, '2026-06-15', 'Widget', 500);
        // Tiny product: same −75% drop but both windows below the $100 USD floor ⇒ no flag.
        $this->seedProduct($brand->id, '2026-05-15', 'Tiny', 80);
        $this->seedProduct($brand->id, '2026-06-15', 'Tiny', 20);

        $out = app(ProductFlags::class)->forBrand($brand->id, $this->start, $this->end);

        $this->assertContains('declining', $this->flagKeys($out, 'Widget'));
        $this->assertNotContains('declining', $this->flagKeys($out, 'Tiny'));
    }

    public function test_high_refunds_warn_then_critical(): void
    {
        $brand = $this->brand();
        $this->seedProduct($brand->id, '2026-06-10', 'Warn', 1000, refunds: 200); // 20% ⇒ warn
        $this->seedProduct($brand->id, '2026-06-10', 'Crit', 1000, refunds: 300); // 30% ⇒ critical

        $out = app(ProductFlags::class)->forBrand($brand->id, $this->start, $this->end);

        $warn = collect($out['Warn']['flags'])->firstWhere('key', 'high_refunds');
        $crit = collect($out['Crit']['flags'])->firstWhere('key', 'high_refunds');
        $this->assertSame('warn', $warn['severity'] ?? null);
        $this->assertSame('critical', $crit['severity'] ?? null);
    }

    public function test_stockout_risk_when_selling_and_low_cover(): void
    {
        $brand = $this->brand();
        $this->seedProduct($brand->id, '2026-06-10', 'Runner', 500, units: 20);
        // ending 10, sold 100 over 90d ⇒ cover = 9 days < 28 ⇒ stockout risk.
        $this->seedSnapshot($brand->id, 'Runner', ending: 10, sold: 100);

        $out = app(ProductFlags::class)->forBrand($brand->id, $this->start, $this->end);

        $this->assertContains('stockout_risk', $this->flagKeys($out, 'Runner'));
        $this->assertSame(9, $out['Runner']['coverDays']);
    }

    public function test_dead_stock_delegated_to_dead_inventory(): void
    {
        $brand = $this->brand();
        $this->seedProduct($brand->id, '2026-06-10', 'Corpse', 200, units: 1);
        // ending 50, sold 0 ⇒ DeadInventory says dead ⇒ dead_stock flag.
        $this->seedSnapshot($brand->id, 'Corpse', ending: 50, sold: 0);

        $out = app(ProductFlags::class)->forBrand($brand->id, $this->start, $this->end);

        $this->assertContains('dead_stock', $this->flagKeys($out, 'Corpse'));
    }
}
