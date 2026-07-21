<?php

declare(strict_types=1);

namespace App\Reports\Mom\Support;

use Carbon\CarbonImmutable;

/**
 * Week-on-week custom range (Kanwar, 2026-07-20): in the MoM report month mode
 * shows month-by-month columns; custom-range mode shows one column per ISO week
 * across the selected range ("week on week progress"). This helper splits a
 * [start, end] window (both brand-tz date strings, `end` already clamped to
 * yesterday by ReportFilters::activeWindow so the running month is included up
 * to the last complete day) into Monday–Sunday ISO weeks, the first and last
 * buckets clamped to the range. One place computes the buckets so every matrix
 * section splits identically.
 */
final class WeekSplit
{
    /** Beyond this many weeks the columns stop being readable; we still show them all (the table scrolls), never dropping data. */
    private const SOFT_MAX_WEEKS = 27;

    /**
     * @return array<int, array{start: string, end: string, label: string}> Monday–Sunday buckets, clamped to [start,end], chronological.
     */
    public static function windows(string $start, string $end, string $tz): array
    {
        $s = CarbonImmutable::parse($start, $tz)->startOfDay();
        $e = CarbonImmutable::parse($end, $tz)->startOfDay();
        if ($e->lessThan($s)) {
            return [];
        }

        $weeks = [];
        $cursor = $s;
        // Guard against a pathological span producing an unbounded loop; the soft
        // cap is far above any sane custom range (~half a year of weeks).
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($e) && $guard < 260) {
            // ISO week ends on the coming Sunday (Mon=1 … Sun=7): daysToSunday =
            // (7 - isoWeekday) % 7, so a Sunday cursor ends the same day.
            $daysToSunday = (7 - $cursor->dayOfWeekIso) % 7;
            $weekEnd = $cursor->addDays($daysToSunday);
            $bucketEnd = $weekEnd->greaterThan($e) ? $e : $weekEnd;

            $weeks[] = [
                'start' => $cursor->toDateString(),
                'end'   => $bucketEnd->toDateString(),
                'label' => self::label($cursor, $bucketEnd),
                // ISO-8601 week-of-year (Mon-based); a clamped partial week keeps
                // the number of the ISO week it belongs to. Shown as "W18".
                'week'  => (int) $cursor->format('W'),
            ];

            $cursor = $bucketEnd->addDay();
            $guard++;
        }

        return $weeks;
    }

    /** True when the split produced more weeks than comfortably fit — callers may add a scroll hint. */
    public static function isCrowded(array $weeks): bool
    {
        return count($weeks) > self::SOFT_MAX_WEEKS;
    }

    /**
     * Period scaffolding for the weekly matrices (Kanwar, 2026-07-21) — the SAME
     * `months` / `monthLabels` keys the month-by-month matrices emit, so the
     * existing S1/S4/S5/S6/S7/S8 frontend renderers draw the weekly view with no
     * new code. `months` carries each week's START date (the per-period key /
     * x-axis), `monthLabels` the compact "1–7 Jun" label, and `weekHeaders` the
     * {week,label} pairs the two-line "W23 / 1–7 Jun" period header reads. Emit
     * these alongside `weekly => true` and each row's `monthly` = per-week cells.
     *
     * @param array<int, array{start:string,end:string,label:string,week:int}> $weeks
     * @return array{months: array<int,string>, monthLabels: array<int,string>, weekHeaders: array<int,array{week:int,label:string}>}
     */
    public static function periods(array $weeks): array
    {
        return [
            'months'      => array_column($weeks, 'start'),
            'monthLabels' => array_column($weeks, 'label'),
            'weekHeaders' => array_map(
                static fn (array $w): array => ['week' => $w['week'], 'label' => $w['label']],
                $weeks,
            ),
        ];
    }

    /**
     * ΔMoM-equivalent for a weekly row: the last week vs the week before it (the
     * momentum column), null-safe. Mirrors the month matrices' "last month vs the
     * month before" so the Δ column reads identically in both modes.
     *
     * @param array<int, float|null> $cells per-week values aligned to windows()
     */
    public static function lastWeekDelta(array $cells): ?float
    {
        $n = count($cells);
        if ($n < 2) {
            return null;
        }
        $last = $cells[$n - 1] ?? null;
        $prev = $cells[$n - 2] ?? null;
        if ($last === null || $prev === null || (float) $prev === 0.0) {
            return null;
        }

        return round(($last - $prev) / $prev * 100, 1);
    }

    /** Shared honesty note for the weekly matrices — week count + the partial-final-week caveat. */
    public static function note(array $weeks): string
    {
        $n = count($weeks);
        $base = $n . ' ' . ($n === 1 ? 'week' : 'weeks')
            . ' · Monday–Sunday; the final week runs to the last complete day (yesterday), so the running month is included.';

        return self::isCrowded($weeks) ? $base . ' Scroll across to see every week.' : $base;
    }

    /** Compact column header: "1–7 Jun" within one month, else "29 Jun–5 Jul". */
    private static function label(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->month === $end->month && $start->year === $end->year) {
            return $start->isoFormat('D') . '–' . $end->isoFormat('D MMM');
        }

        return $start->isoFormat('D MMM') . '–' . $end->isoFormat('D MMM');
    }
}
