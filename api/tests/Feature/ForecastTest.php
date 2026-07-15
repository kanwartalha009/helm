<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Services\Rules\Forecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-2.3 — forecast baseline (seasonal-naive + drift, fpp3 §5.2).
 *
 * The most important tests here are the REFUSALS. An engine that quietly extrapolates
 * from thin history produces a confident-looking number with nothing underneath it,
 * and one such number in a client plan costs more trust than the forecast will ever
 * earn back. So: too little history → no numbers at all. Last year doesn't cover the
 * window → no numbers at all.
 */
final class ForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixed "today" so last-year dates are deterministic. 2025 is not a leap year,
        // so subYear() maps cleanly day-for-day.
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

    private function day(Brand $b, string $date, float $revenue, bool $complete = true): void
    {
        DB::table('daily_metrics')->insert([
            'brand_id' => $b->id, 'platform' => 'shopify', 'date' => $date,
            'total_sales' => $revenue, 'refunds_amount' => 0, 'orders' => 1,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => $complete, 'pulled_at' => now(),
        ]);
    }

    /** Seed a run of consecutive days. */
    private function seedRun(Brand $b, string $from, int $days, float $revenue): void
    {
        $d = CarbonImmutable::parse($from);
        for ($i = 0; $i < $days; $i++) {
            $this->day($b, $d->addDays($i)->toDateString(), $revenue);
        }
    }

    public function test_refuses_when_the_brand_is_too_new(): void
    {
        // 30 days of history; the baseline needs 90. It must return NO numbers.
        $brand = $this->brand();
        $this->seedRun($brand, '2026-05-16', 30, 100);

        $f = app(Forecast::class)->forBrand($brand->fresh(), 30);

        $this->assertSame('insufficient_history', $f['status']);
        $this->assertArrayNotHasKey('days', $f);        // no fabricated series
        $this->assertArrayNotHasKey('totals', $f);      // no fabricated total
        $this->assertStringContainsString('90', $f['reason']);
        $this->assertStringContainsString('Modeled', $f['label']);
    }

    public function test_refuses_when_last_year_does_not_cover_the_window(): void
    {
        // Plenty of RECENT history (so gate 1 passes) but nothing a year ago → the
        // seasonal term has nothing to stand on. Refuse rather than invent.
        $brand = $this->brand();
        $this->seedRun($brand, '2026-01-01', 160, 100);   // ≥90 complete days, all this year

        $f = app(Forecast::class)->forBrand($brand->fresh(), 30);

        $this->assertSame('insufficient_history', $f['status']);
        $this->assertArrayNotHasKey('totals', $f);
        $this->assertStringContainsString('Last year covers only', $f['reason']);
    }

    public function test_seasonal_naive_matches_a_hand_computed_fixture(): void
    {
        $brand = $this->brand();

        // Gate 1: ≥90 complete days of history.
        $this->seedRun($brand, '2026-01-01', 150, 50);

        // Last year's window (2025-06-15 … 2025-06-24) = 10 days at 200/day.
        $this->seedRun($brand, '2025-06-15', 10, 200);

        // Trend windows: make this year's trailing 28d EQUAL last year's, so trend = 1.0
        // and the forecast is exactly the seasonal term. (Both windows already have
        // complete days from the runs above; assert the trend explicitly below.)
        $f = app(Forecast::class)->forBrand($brand->fresh(), 10);

        $this->assertSame('ok', $f['status']);
        $this->assertSame(100, $f['coverage']['pct']);        // last year covers all 10 days
        $this->assertSame(0, $f['coverage']['missingDays']);

        // Hand-computed: each of the 10 days = 200 (last year) × trend.
        $expectedPerDay = 200.0 * (float) $f['trend'];
        $this->assertEqualsWithDelta($expectedPerDay, $f['days'][0]['forecast'], 0.01);
        $this->assertEqualsWithDelta(200.0, $f['days'][0]['seasonal'], 0.01);
        $this->assertEqualsWithDelta($expectedPerDay * 10, $f['totals']['forecast'], 0.1);
        // The un-trended baseline is shown alongside so the trend can be backed out.
        $this->assertEqualsWithDelta(2000.0, $f['totals']['seasonalOnly'], 0.01);
    }

    public function test_trend_is_the_ratio_of_trailing_28d_to_the_same_window_last_year(): void
    {
        $brand = $this->brand();

        // Last year's trailing 28d (2025-05-18 … 2025-06-14): 100/day.
        $this->seedRun($brand, '2025-05-18', 28, 100);
        // This year's trailing 28d (2026-05-18 … 2026-06-14): 150/day → trend 1.5×.
        $this->seedRun($brand, '2026-05-18', 28, 150);
        // Gate 1 padding + last year's forecast window.
        $this->seedRun($brand, '2026-01-01', 120, 100);
        $this->seedRun($brand, '2025-06-15', 10, 200);

        $f = app(Forecast::class)->forBrand($brand->fresh(), 10);

        $this->assertSame('ok', $f['status']);
        $this->assertTrue($f['trendApplied']);
        $this->assertEqualsWithDelta(1.5, (float) $f['trend'], 0.01);
        // seasonal 200 × trend 1.5 = 300/day.
        $this->assertEqualsWithDelta(300.0, $f['days'][0]['forecast'], 0.01);
    }

    public function test_absurd_trend_is_clamped_and_disclosed(): void
    {
        $brand = $this->brand();

        // Last year's 28d was near-zero (1/day); this year 500/day → raw trend 500×.
        // That is an artefact of a tiny base, not momentum. It must be clamped to 2.0×
        // AND the payload must say it was clamped.
        $this->seedRun($brand, '2025-05-18', 28, 1);
        $this->seedRun($brand, '2026-05-18', 28, 500);
        $this->seedRun($brand, '2026-01-01', 120, 100);
        $this->seedRun($brand, '2025-06-15', 10, 200);

        $f = app(Forecast::class)->forBrand($brand->fresh(), 10);

        $this->assertSame('ok', $f['status']);
        $this->assertEqualsWithDelta(2.0, (float) $f['trend'], 0.001);   // clamped
        $this->assertTrue($f['trendClamped']);
        $this->assertStringContainsString('clamped', $f['trendNote']);
    }

    public function test_gaps_in_last_year_are_missing_not_zero(): void
    {
        $brand = $this->brand();
        $this->seedRun($brand, '2026-01-01', 150, 100);

        // Last year's window has 8 of 10 days (two gaps). Coverage 80% ≥ 70% → forecast
        // proceeds, but the missing days contribute NOTHING and are reported as missing
        // rather than silently modelled as €0 revenue.
        $this->seedRun($brand, '2025-06-15', 8, 100);   // 2025-06-15 … 06-22; 06-23/24 absent

        $f = app(Forecast::class)->forBrand($brand->fresh(), 10);

        $this->assertSame('ok', $f['status']);
        $this->assertSame(80, $f['coverage']['pct']);
        $this->assertSame(2, $f['coverage']['missingDays']);

        $missing = collect($f['days'])->firstWhere('date', '2026-06-24');
        $this->assertNull($missing['seasonal']);    // absent, not 0
        $this->assertNull($missing['forecast']);
    }

    public function test_endpoint_ships_the_modeled_label(): void
    {
        $brand = $this->brand();
        $this->seedRun($brand, '2026-01-01', 150, 100);
        $this->seedRun($brand, '2025-06-15', 30, 200);

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->getJson("/api/brands/{$brand->slug}/forecast?horizon=30")->assertOk()->json();

        // §0 law 1: a forecast may never render without its Modeled label.
        $this->assertStringContainsString('Modeled — baseline forecast', $res['label']);
        $this->assertSame('ok', $res['status']);
    }
}
