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

    public function test_from_array_parses_month_week_and_platform(): void
    {
        $f = ReportFilters::fromArray([
            'month'    => '2026-05',
            'week'     => '2026-06-15', // a Monday
            'platform' => 'google',
        ]);

        $this->assertSame('2026-05', $f->month);
        $this->assertSame('2026-06-15', $f->week);
        $this->assertSame('google', $f->platform);

        // Defaults stay null when the params are absent.
        $empty = ReportFilters::fromArray([]);
        $this->assertNull($empty->month);
        $this->assertNull($empty->week);
        $this->assertNull($empty->platform);
    }

    public function test_from_array_rejects_malformed_month_week_and_platform(): void
    {
        $f = ReportFilters::fromArray([
            'month'    => '2026-13',     // no month 13
            'week'     => '2026-06-16',  // a Tuesday — not a Monday
            'platform' => 'bing',        // not an ad platform Helm syncs
        ]);

        $this->assertNull($f->month);
        $this->assertNull($f->week);
        $this->assertNull($f->platform);

        $this->assertNull(ReportFilters::fromArray(['month' => 'garbage'])->month);
        $this->assertNull(ReportFilters::fromArray(['month' => '2026-05-01'])->month);
        $this->assertNull(ReportFilters::fromArray(['week' => '2026-06'])->week);
        $this->assertNull(ReportFilters::fromArray(['week' => 'not-a-date'])->week);
        $this->assertNull(ReportFilters::fromArray(['platform' => ['meta']])->platform);
    }

    public function test_month_window_only_resolves_complete_months(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', self::TZ));

        // A complete past month resolves to [first, last].
        $june = ReportFilters::fromArray(['month' => '2026-06']);
        $this->assertSame(['2026-06-01', '2026-06-30'], $june->monthWindow(self::TZ));

        // The CURRENT month is incomplete → null, never a partial window.
        $july = ReportFilters::fromArray(['month' => '2026-07']);
        $this->assertNull($july->monthWindow(self::TZ));

        // No month requested → null.
        $this->assertNull(ReportFilters::fromArray([])->monthWindow(self::TZ));

        // Boundary: on the 1st, the just-finished month IS complete (its last
        // day is yesterday).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 09:00:00', self::TZ));
        $this->assertSame(['2026-06-01', '2026-06-30'], $june->monthWindow(self::TZ));
    }

    public function test_week_window_only_resolves_complete_weeks(): void
    {
        // 2026-07-10 is a Friday: the week of Mon 2026-07-06 is still running.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', self::TZ));

        $complete = ReportFilters::fromArray(['week' => '2026-06-29']); // Mon, ends Sun 07-05 ≤ yesterday
        $this->assertSame(['2026-06-29', '2026-07-05'], $complete->weekWindow(self::TZ));

        $running = ReportFilters::fromArray(['week' => '2026-07-06']); // Mon of the current week
        $this->assertSame('2026-07-06', $running->week);               // parses fine (it IS a Monday) …
        $this->assertNull($running->weekWindow(self::TZ));             // … but the window refuses it

        // No week requested → null.
        $this->assertNull(ReportFilters::fromArray([])->weekWindow(self::TZ));
    }
}
