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
 * M5 addendum (Kanwar, 2026-07-15 — "we need last 3 month minimum comparison
 * can be 4, 6, or 12 month... compare those period with previous year") —
 * S1's new `months` trailing-window mode. Report month is fixed to March
 * 2026 specifically because a 6-month trailing window from March crosses a
 * calendar-year boundary (Oct 2025-Mar 2026), which the default
 * always-Jan-start `buildRows()` path can never exercise — this is the exact
 * case `buildTrailingRows()` exists for.
 */
final class MomFinancialMatrixWindowTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';
    private const REPORT_MONTH = '2026-03';

    private function makeBrand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);
    }

    private function actingMasterAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
    }

    /** Seed one shopify + one meta row for every month in [start, end] inclusive, revenue distinct per month so rows are identifiable. */
    private function seedMonths(int $brandId, string $start, string $end): void
    {
        $d = CarbonImmutable::parse($start . '-01');
        $endDate = CarbonImmutable::parse($end . '-01');
        while ($d->lessThanOrEqualTo($endDate)) {
            $revenue = 1000 + ((int) $d->format('Ym'));
            DB::table('daily_metrics')->insert([
                'brand_id' => $brandId, 'platform' => 'shopify', 'date' => $d->toDateString(),
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                'total_sales' => $revenue, 'refunds_amount' => 0, 'orders' => 10,
            ]);
            DB::table('daily_metrics')->insert([
                'brand_id' => $brandId, 'platform' => 'meta', 'date' => $d->addDays(1)->toDateString(),
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                'spend' => 100,
            ]);
            $d = $d->addMonthNoOverflow()->startOfMonth();
        }
    }

    public function test_default_unset_months_reproduces_the_original_full_year_tables(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedMonths($brand->id, '2024-12', self::REPORT_MONTH);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S1?month=" . self::REPORT_MONTH)->assertOk();

        $this->assertNull($res->json('monthsWindow'));
        $this->assertCount(3, $res->json('currentYearRows'));  // Jan, Feb, Mar 2026
        $this->assertCount(12, $res->json('priorYearRows'));   // full 2025
        $this->assertSame('March 2026', $res->json('currentYearRows.2.label'));
    }

    public function test_months_6_returns_a_trailing_window_crossing_the_year_boundary(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        // Current window needs Oct'25..Mar'26 (+1 lookback = Sep'25); prior
        // window needs Oct'24..Mar'25 (+1 lookback = Sep'24).
        $this->seedMonths($brand->id, '2024-09', self::REPORT_MONTH);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S1?month=" . self::REPORT_MONTH . '&months=6')->assertOk();

        $this->assertSame(6, $res->json('monthsWindow'));
        $current = $res->json('currentYearRows');
        $prior = $res->json('priorYearRows');
        $this->assertCount(6, $current);
        $this->assertCount(6, $prior);

        // Current window: Oct 2025 .. Mar 2026, chronological, crossing the year boundary.
        $this->assertSame(['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'], array_column($current, 'month'));
        // Prior window: the SAME 6 calendar months, one year earlier.
        $this->assertSame(['2024-10', '2024-11', '2024-12', '2025-01', '2025-02', '2025-03'], array_column($prior, 'month'));

        // The report month's own row still has a real MoM delta (proves the
        // one-extra-month lookback query actually reached back far enough).
        $marchRow = $current[5];
        $this->assertSame('2026-03', $marchRow['month']);
        $this->assertNotNull($marchRow['deltaRevenuePct']);
    }

    public function test_an_invalid_months_value_is_ignored_not_guessed(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $this->seedMonths($brand->id, '2024-12', self::REPORT_MONTH);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S1?month=" . self::REPORT_MONTH . '&months=7')->assertOk();

        $this->assertNull($res->json('monthsWindow'));
        $this->assertCount(3, $res->json('currentYearRows'));
    }
}
