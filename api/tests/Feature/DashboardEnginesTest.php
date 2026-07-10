<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Services\Aggregation\DashboardQuery;
use App\Services\Aggregation\DashboardQuerySetBased;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The dashboard math contract, asserted against BOTH engines (legacy
 * DashboardQuery and the set-based DashboardQuerySetBased), plus a deep
 * equivalence check between them on a rich dataset. These are the first
 * tests on the highest-stakes rules in the product: missing data is never
 * zero, partial days never surface, refunds add back into total revenue,
 * USD conversion uses the stored snapshot rate.
 *
 * Rows are seeded through DB::table() with date-only strings — Eloquent's
 * `date` cast stores '00:00:00' suffixes on sqlite, which silently breaks
 * exact-date matching that works fine on MySQL's DATE column.
 */
final class DashboardEnginesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, DashboardQuery|DashboardQuerySetBased> */
    private function engines(): array
    {
        return [
            'legacy' => app(DashboardQuery::class),
            'set'    => app(DashboardQuerySetBased::class),
        ];
    }

    private function brand(array $attrs = []): Brand
    {
        return Brand::factory()->create(array_merge([
            'timezone'      => 'UTC',
            'base_currency' => 'EUR',
            'status'        => 'active',
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function metric(int $brandId, string $platform, string $date, array $attrs = []): void
    {
        DB::table('daily_metrics')->insert(array_merge([
            'brand_id'       => $brandId,
            'platform'       => $platform,
            'date'           => $date,
            'revenue'        => null,
            'revenue_net'    => null,
            'net_sales'      => null,
            'total_sales'    => null,
            'orders'         => null,
            'refunds_amount' => null,
            'spend'          => null,
            'currency'       => 'EUR',
            'fx_rate_to_usd' => 1.0,
            'is_complete'    => true,
            'pulled_at'      => now(),
        ], $attrs));
    }

    /** Seven complete Shopify days ending yesterday (UTC). */
    private function seedCompleteWeek(int $brandId, array $attrs = []): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        for ($i = 1; $i <= 7; $i++) {
            $this->metric($brandId, 'shopify', $today->subDays($i)->toDateString(), array_merge([
                'revenue'        => 100,
                'net_sales'      => 80,
                'total_sales'    => 90,
                'orders'         => 5,
                'refunds_amount' => 10,
            ], $attrs));
        }
    }

    /** Strip the set-engine's additive keys so payloads are comparable. */
    private function stripAdditive(array $rows): array
    {
        foreach ($rows as &$row) {
            unset($row['yesterday']['fxPending'], $row['dayBefore']['fxPending'], $row['rolling']['fxPendingDays']);
        }

        return $rows;
    }

    public function test_missing_data_is_not_zero(): void
    {
        $brand = $this->brand();
        $today = CarbonImmutable::now('UTC')->startOfDay();

        // Only 6 of the 7 window days synced — the window must render as
        // missing (null), NEVER as a €0-ish partial sum (docs/10 rule 9).
        for ($i = 2; $i <= 7; $i++) {
            $this->metric($brand->id, 'shopify', $today->subDays($i)->toDateString(), [
                'revenue' => 100, 'net_sales' => 80, 'total_sales' => 90, 'refunds_amount' => 0,
            ]);
        }

        foreach ($this->engines() as $name => $engine) {
            $row = $engine->run(['manager' => 'all', 'window' => 7])[0];

            $this->assertNull($row['rolling']['revenue'], "{$name}: partial window must be null");
            $this->assertFalse($row['rolling']['isComplete'], $name);
            $this->assertNull($row['yesterday']['revenue'], "{$name}: absent yesterday must be null");
            $this->assertFalse($row['yesterday']['isComplete'], $name);
        }
    }

    public function test_complete_window_sums_and_refund_addback(): void
    {
        $brand = $this->brand();
        $this->seedCompleteWeek($brand->id);

        foreach ($this->engines() as $name => $engine) {
            $row = $engine->run(['manager' => 'all', 'window' => 7])[0];

            $this->assertTrue($row['rolling']['isComplete'], $name);
            $this->assertEqualsWithDelta(700.0, $row['rolling']['revenueGross'], 0.01, $name);
            $this->assertEqualsWithDelta(630.0, $row['rolling']['revenue'], 0.01, "{$name}: net = gross − refunds");
            $this->assertEqualsWithDelta(560.0, $row['rolling']['netSales'], 0.01, $name);
            // Total revenue adds refunds back: (90 + 10) × 7.
            $this->assertEqualsWithDelta(700.0, $row['rolling']['totalSales'], 0.01, $name);

            $this->assertEqualsWithDelta(100.0, $row['yesterday']['revenue'], 0.01, $name);
            $this->assertEqualsWithDelta(90.0, $row['yesterday']['revenueNet'], 0.01, $name);
            $this->assertEqualsWithDelta(80.0, $row['yesterday']['netSales'], 0.01, $name);
            $this->assertEqualsWithDelta(100.0, $row['yesterday']['totalSales'], 0.01, "{$name}: total revenue adds refunds back");
            $this->assertEqualsWithDelta(10.0, $row['yesterday']['refundsAmount'], 0.01, $name);
        }
    }

    public function test_partial_day_never_surfaces(): void
    {
        $brand     = $this->brand();
        $yesterday = CarbonImmutable::now('UTC')->subDay()->toDateString();

        $this->metric($brand->id, 'shopify', $yesterday, [
            'revenue' => 999, 'net_sales' => 900, 'total_sales' => 950, 'is_complete' => false,
        ]);

        foreach ($this->engines() as $name => $engine) {
            $row = $engine->run(['manager' => 'all', 'window' => 7])[0];

            $this->assertNull($row['yesterday']['revenue'], "{$name}: a half-day number must never surface");
            $this->assertNull($row['yesterday']['totalSales'], $name);
            $this->assertNull($row['yesterday']['roasTotal'], $name);
            $this->assertFalse($row['yesterday']['isComplete'], $name);
        }
    }

    public function test_usd_mode_uses_snapshot_rate_and_flags_pending_fx(): void
    {
        $brand = $this->brand();
        $today = CarbonImmutable::now('UTC')->startOfDay();

        // 6 days with a stored 0.5 rate, yesterday with NO rate yet.
        for ($i = 2; $i <= 7; $i++) {
            $this->metric($brand->id, 'shopify', $today->subDays($i)->toDateString(), [
                'revenue' => 100, 'net_sales' => 80, 'total_sales' => 90, 'refunds_amount' => 0,
                'fx_rate_to_usd' => 0.5,
            ]);
        }
        $this->metric($brand->id, 'shopify', $today->subDay()->toDateString(), [
            'revenue' => 100, 'net_sales' => 80, 'total_sales' => 90, 'refunds_amount' => 0,
            'fx_rate_to_usd' => null,
        ]);

        $expectedGross = 6 * 100 * 0.5 + 100 * 1.0; // legacy COALESCE(fx, 1) fallback

        foreach ($this->engines() as $name => $engine) {
            $row = $engine->run(['manager' => 'all', 'window' => 7, 'currency' => 'USD'])[0];

            $this->assertEqualsWithDelta($expectedGross, $row['rolling']['revenueGross'], 0.01, $name);
        }

        // The set engine additionally FLAGS the un-backfilled day so the SPA
        // can render it as pending instead of silently trusting rate 1.0.
        $setRow = app(DashboardQuerySetBased::class)->run(['manager' => 'all', 'window' => 7, 'currency' => 'USD'])[0];
        $this->assertSame(1, $setRow['rolling']['fxPendingDays']);
        $this->assertTrue($setRow['yesterday']['fxPending']);
    }

    public function test_roas_follows_metric_and_blends_all_ad_platforms(): void
    {
        $brand     = $this->brand();
        $yesterday = CarbonImmutable::now('UTC')->subDay()->toDateString();

        $this->metric($brand->id, 'shopify', $yesterday, [
            'revenue' => 100, 'net_sales' => 80, 'total_sales' => 90, 'refunds_amount' => 10,
        ]);
        $this->metric($brand->id, 'meta', $yesterday, ['spend' => 50, 'currency' => 'USD']);
        $this->metric($brand->id, 'google', $yesterday, ['spend' => 25, 'currency' => 'USD']);

        foreach ($this->engines() as $name => $engine) {
            $row = $engine->run(['manager' => 'all', 'window' => 7])[0];

            $this->assertEqualsWithDelta(75.0, $row['yesterday']['totalSpend'], 0.01, $name);
            $this->assertEqualsWithDelta(round(80 / 75, 2), $row['yesterday']['roas'], 0.001, "{$name}: net ROAS");
            $this->assertEqualsWithDelta(round(100 / 75, 2), $row['yesterday']['roasTotal'], 0.001, "{$name}: total ROAS adds refunds back");
            $this->assertNull($row['yesterday']['tiktokSpend'], "{$name}: unconnected platform is null, not 0");
        }
    }

    public function test_sort_is_revenue_desc_then_zero_then_unknown(): void
    {
        $top    = $this->brand(['name' => 'Zeta Winner']);   // revenue > 0
        $zero   = $this->brand(['name' => 'Alpha Zero']);    // confirmed 0
        $ghost  = $this->brand(['name' => 'Beta Ghost']);    // no rows at all

        $this->seedCompleteWeek($top->id);
        $this->seedCompleteWeek($zero->id, ['revenue' => 0, 'net_sales' => 0, 'total_sales' => 0, 'refunds_amount' => 0]);

        foreach ($this->engines() as $name => $engine) {
            $rows  = $engine->run(['manager' => 'all', 'window' => 7]);
            $order = array_column(array_column($rows, 'brand'), 'name');

            $this->assertSame(['Zeta Winner', 'Alpha Zero', 'Beta Ghost'], $order, $name);
        }
    }

    public function test_engines_agree_cell_for_cell_on_a_rich_dataset(): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $a = $this->brand(['name' => 'Brand A']);
        $b = $this->brand(['name' => 'Brand B', 'base_currency' => 'USD']);
        $c = $this->brand(['name' => 'Brand C']); // no data at all

        // A: full 30-day window + prior + last year, mixed FX, ads on some days.
        for ($i = 1; $i <= 62; $i++) {
            $this->metric($a->id, 'shopify', $today->subDays($i)->toDateString(), [
                'revenue'        => 50 + $i,
                'net_sales'      => 40 + $i,
                'total_sales'    => 45 + $i,
                'orders'         => $i % 7,
                'refunds_amount' => $i % 3,
                'fx_rate_to_usd' => $i % 5 === 0 ? null : 1.08,
            ]);
            if ($i % 2 === 0) {
                $this->metric($a->id, 'meta', $today->subDays($i)->toDateString(), [
                    'spend' => 10 + $i, 'currency' => 'USD',
                ]);
            }
        }
        for ($i = 366; $i <= 396; $i++) { // last-year window for YoY
            $this->metric($a->id, 'shopify', $today->subDays($i)->toDateString(), [
                'revenue' => 30, 'net_sales' => 25, 'total_sales' => 28, 'refunds_amount' => 2, 'fx_rate_to_usd' => 1.1,
            ]);
        }

        // B: sparse — incomplete days, one partial yesterday, google spend only.
        $this->metric($b->id, 'shopify', $today->subDay()->toDateString(), [
            'revenue' => 200, 'net_sales' => 150, 'total_sales' => 180, 'refunds_amount' => 20, 'is_complete' => false, 'currency' => 'USD',
        ]);
        $this->metric($b->id, 'shopify', $today->subDays(2)->toDateString(), [
            'revenue' => 210, 'net_sales' => 160, 'total_sales' => 190, 'refunds_amount' => 5, 'currency' => 'USD',
        ]);
        $this->metric($b->id, 'google', $today->subDays(2)->toDateString(), ['spend' => 33, 'currency' => 'USD']);

        $paramSets = [
            ['manager' => 'all', 'window' => 7],
            ['manager' => 'all', 'window' => 30, 'currency' => 'USD'],
            ['manager' => 'all', 'window' => 7, 'compare' => 'yesterday,last7,last30,lastmonth,mtd', 'metric' => 'total'],
            ['manager' => 'all', 'window' => 30, 'compare' => 'last30', 'metric' => 'net', 'currency' => 'USD'],
        ];

        foreach ($paramSets as $params) {
            $legacy = app(DashboardQuery::class)->run($params);
            $set    = $this->stripAdditive(app(DashboardQuerySetBased::class)->run($params));

            $this->assertSame(
                json_encode($legacy, JSON_PRETTY_PRINT),
                json_encode($set, JSON_PRETTY_PRINT),
                'engines diverged for params ' . json_encode($params),
            );
        }

        $this->assertNotEmpty($c->id); // silence unused-variable linters
    }
}
