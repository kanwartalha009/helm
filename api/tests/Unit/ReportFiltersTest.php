<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Reports\Contracts\ReportFilters;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Window resolution for the shared report filters — specifically the two clamp
 * rules: mtd on the 1st of the month (no complete day yet) falls back to the
 * full previous month instead of an inverted start>end window, and a custom
 * `to` of today or later clamps to yesterday so a report never includes a
 * partial day.
 */
final class ReportFiltersTest extends TestCase
{
    private const TZ = 'Europe/Madrid';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function filters(string $period, ?string $from = null, ?string $to = null): ReportFilters
    {
        return new ReportFilters(period: $period, from: $from, to: $to, compare: 'previous', usd: false);
    }

    public function test_mtd_mid_month_runs_from_month_start_to_yesterday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', self::TZ));

        [$start, $end] = $this->filters('mtd')->window(self::TZ);

        $this->assertSame('2026-07-01', $start);
        $this->assertSame('2026-07-09', $end);
    }

    public function test_mtd_on_the_first_clamps_to_the_full_previous_month(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 09:00:00', self::TZ));

        [$start, $end] = $this->filters('mtd')->window(self::TZ);

        // Never start (2026-07-01) > end (2026-06-30): the window is the whole
        // previous month instead.
        $this->assertSame('2026-06-01', $start);
        $this->assertSame('2026-06-30', $end);
        $this->assertLessThanOrEqual($end, $start);
    }

    public function test_custom_to_in_the_future_clamps_to_yesterday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', self::TZ));

        [$start, $end] = $this->filters('custom', '2026-07-01', '2026-07-25')->window(self::TZ);

        $this->assertSame('2026-07-01', $start);
        $this->assertSame('2026-07-09', $end); // clamped: today + future days are partial

        // A custom `to` of today itself is also partial → clamps too.
        [, $endToday] = $this->filters('custom', '2026-07-01', '2026-07-10')->window(self::TZ);
        $this->assertSame('2026-07-09', $endToday);
    }

    public function test_custom_to_in_the_past_is_untouched(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', self::TZ));

        [$start, $end] = $this->filters('custom', '2026-06-01', '2026-06-15')->window(self::TZ);

        $this->assertSame('2026-06-01', $start);
        $this->assertSame('2026-06-15', $end);
    }
}
