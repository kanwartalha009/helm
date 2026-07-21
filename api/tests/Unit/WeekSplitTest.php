<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Reports\Mom\Support\WeekSplit;
use PHPUnit\Framework\TestCase;

/**
 * Week-on-week custom range (Kanwar, 2026-07-20): the MoM matrices split a
 * custom range into Monday–Sunday ISO weeks. This locks the bucketing — full
 * weeks, a clamped first/last partial week, and the running-month case where
 * `end` is yesterday mid-week.
 */
class WeekSplitTest extends TestCase
{
    private const TZ = 'Europe/Madrid';

    public function test_a_two_week_range_on_clean_monday_boundaries_yields_two_full_weeks(): void
    {
        // Jun 1 2026 is a Monday; Jun 1–14 is exactly two ISO weeks.
        $weeks = WeekSplit::windows('2026-06-01', '2026-06-14', self::TZ);

        $this->assertCount(2, $weeks);
        $this->assertSame('2026-06-01', $weeks[0]['start']);
        $this->assertSame('2026-06-07', $weeks[0]['end']);
        $this->assertSame('2026-06-08', $weeks[1]['start']);
        $this->assertSame('2026-06-14', $weeks[1]['end']);
    }

    public function test_the_first_and_last_partial_weeks_are_clamped_to_the_range(): void
    {
        // Jun 3 (Wed) → Jun 16 (Tue): partial first week Jun 3–7, full Jun 8–14,
        // partial last week Jun 15–16. Buckets never spill past the range.
        $weeks = WeekSplit::windows('2026-06-03', '2026-06-16', self::TZ);

        $this->assertCount(3, $weeks);
        $this->assertSame(['2026-06-03', '2026-06-07'], [$weeks[0]['start'], $weeks[0]['end']]);
        $this->assertSame(['2026-06-08', '2026-06-14'], [$weeks[1]['start'], $weeks[1]['end']]);
        $this->assertSame(['2026-06-15', '2026-06-16'], [$weeks[2]['start'], $weeks[2]['end']]);
    }

    public function test_running_month_range_ending_yesterday_mid_week_keeps_a_partial_final_week(): void
    {
        // The running month: report is viewed 2026-07-20, so a "July so far" range
        // ends at yesterday (Jul 19, a Sunday here) — but even a mid-week end must
        // produce a clamped final bucket, never drop the days. Jul 1 2026 is a Wed.
        $weeks = WeekSplit::windows('2026-07-01', '2026-07-16', self::TZ);

        $this->assertSame('2026-07-01', $weeks[0]['start']);      // Wed, partial first week
        $this->assertSame('2026-07-05', $weeks[0]['end']);        // clamped to that week's Sunday
        $lastIndex = count($weeks) - 1;
        $this->assertSame('2026-07-16', $weeks[$lastIndex]['end']); // partial final week ends at the range end
    }

    public function test_a_single_day_range_is_one_bucket(): void
    {
        $weeks = WeekSplit::windows('2026-06-10', '2026-06-10', self::TZ);

        $this->assertCount(1, $weeks);
        $this->assertSame(['2026-06-10', '2026-06-10'], [$weeks[0]['start'], $weeks[0]['end']]);
    }

    public function test_an_inverted_range_yields_no_weeks(): void
    {
        $this->assertSame([], WeekSplit::windows('2026-06-14', '2026-06-01', self::TZ));
    }
}
