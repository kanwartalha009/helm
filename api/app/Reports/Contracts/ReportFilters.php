<?php

declare(strict_types=1);

namespace App\Reports\Contracts;

use Carbon\CarbonImmutable;

/**
 * The shared filter set every report accepts: a period window and a comparison
 * window, both resolved in the BRAND's timezone (never UTC — spec rule 8), plus
 * the display-currency flag. Windows end at yesterday so a report never shows a
 * partial "today". Kept tiny and immutable; reused by every ReportType.
 */
final class ReportFilters
{
    public function __construct(
        public readonly string $period,   // last7 | last30 | mtd | custom
        public readonly ?string $from,    // Y-m-d, custom only
        public readonly ?string $to,      // Y-m-d, custom only
        public readonly string $compare,  // previous | last_year | none
        public readonly bool $usd,        // display currency: USD vs brand native
        public readonly ?string $month = null,    // Y-m — monthly report month selector
        public readonly ?string $week = null,     // Y-m-d Monday — weekly report week selector
        public readonly ?string $platform = null, // meta | google | tiktok — platform-scoped reports
        // REV2 R3 (monthly-report-v2-mom.md): explicit second month for month-based
        // comparisons — "Custom: pick ANY second month, e.g. Nov 2026 vs Nov 2024".
        // When set it OVERRIDES the derived previous/last_year month below. Additive;
        // v1/other report types never set this and are unaffected.
        public readonly ?string $compareMonth = null, // Y-m
        // M5 addendum (Kanwar, 2026-07-15): trailing-window length for S1's
        // financial matrix — "last 3/4/6/12 months vs the same months last
        // year" as an alternative to the section's default always-Jan-start
        // full-year tables. Additive and mom-S1-specific; null (unset or an
        // unrecognised value) preserves every other report type's/section's
        // existing behaviour exactly.
        public readonly ?int $months = null,
        // Kanwar, 2026-07-16: a ROAS benchmark the S6 ROAS-by-country matrix
        // colours and flags against (green above / red below), overriding the
        // config default floor. Additive and mom-S6-specific; null = use the
        // config default, so every other section is unaffected.
        public readonly ?float $benchmark = null,
    ) {}

    /** @param array<string, mixed> $p query params */
    public static function fromArray(array $p): self
    {
        $period  = in_array($p['period'] ?? null, ['last7', 'last30', 'mtd', 'custom'], true) ? (string) $p['period'] : 'last30';
        $compare = in_array($p['compare'] ?? null, ['previous', 'last_year', 'none'], true) ? (string) $p['compare'] : 'previous';
        $ymd     = static fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        // month must be a real 'YYYY-MM'; week must be a Y-m-d that lands on a
        // Monday (anything else is a malformed selector → null, never a guess).
        $month = ($m = $p['month'] ?? null) !== null && is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : null;
        $week  = $ymd($p['week'] ?? null);
        if ($week !== null && CarbonImmutable::parse($week)->dayOfWeekIso !== 1) {
            $week = null;
        }
        $ym = static fn ($v) => is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : null;

        return new self(
            period:  $period,
            from:    $ymd($p['from'] ?? null),
            to:      $ymd($p['to'] ?? null),
            compare: $compare,
            usd:     strtoupper((string) ($p['currency'] ?? '')) === 'USD',
            month:   $month,
            week:    $week,
            platform: in_array($p['platform'] ?? null, ['meta', 'google', 'tiktok'], true) ? (string) $p['platform'] : null,
            compareMonth: $ym($p['compare_month'] ?? null),
            months: in_array((int) ($p['months'] ?? 0), [3, 4, 6, 12], true) ? (int) $p['months'] : null,
            benchmark: is_numeric($p['benchmark'] ?? null) && (float) $p['benchmark'] > 0.0 ? round((float) $p['benchmark'], 2) : null,
        );
    }

    /**
     * [first, last] day of the requested month — ONLY when that month is
     * complete in the brand tz (last day ≤ yesterday), else null: a partial
     * month is never sent to a client.
     *
     * @return array{0: string, 1: string}|null
     */
    public function monthWindow(string $tz): ?array
    {
        if ($this->month === null) {
            return null;
        }

        $start     = CarbonImmutable::parse($this->month . '-01', $tz)->startOfDay();
        $end       = $start->endOfMonth()->startOfDay();
        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();

        return $end->lessThanOrEqualTo($yesterday) ? [$start->toDateString(), $end->toDateString()] : null;
    }

    /**
     * [monday, sunday] of the requested ISO week — ONLY when the week is
     * complete in the brand tz (sunday ≤ yesterday), else null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function weekWindow(string $tz): ?array
    {
        if ($this->week === null) {
            return null;
        }

        $monday    = CarbonImmutable::parse($this->week, $tz)->startOfDay();
        $sunday    = $monday->addDays(6);
        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();

        return $sunday->lessThanOrEqualTo($yesterday) ? [$monday->toDateString(), $sunday->toDateString()] : null;
    }

    /**
     * [start, end] date strings (brand tz) for the selected period.
     *
     * mtd on the 1st of the month has no complete day yet (start would land
     * AFTER yesterday, inverting the window), so it clamps to the full previous
     * month. A custom `to` of today or later would include a partial day, so it
     * clamps to yesterday.
     *
     * @return array{0: string, 1: string}
     */
    public function window(string $tz): array
    {
        $now       = CarbonImmutable::now($tz);
        $yesterday = $now->subDay()->startOfDay();

        return match ($this->period) {
            'last7'  => [$yesterday->subDays(6)->toDateString(), $yesterday->toDateString()],
            'mtd'    => $now->day === 1
                ? [$yesterday->startOfMonth()->toDateString(), $yesterday->toDateString()]
                : [$now->startOfMonth()->toDateString(), $yesterday->toDateString()],
            'custom' => [
                $this->from ?? $yesterday->subDays(29)->toDateString(),
                min($this->to ?? $yesterday->toDateString(), $yesterday->toDateString()),
            ],
            default  => [$yesterday->subDays(29)->toDateString(), $yesterday->toDateString()], // last30
        };
    }

    /**
     * [start, end] for the comparison window, or [null, null] when compare=none.
     * previous  = the equal-length window immediately before the period.
     * last_year = the same calendar dates one year earlier.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function comparisonWindow(string $tz): array
    {
        if ($this->compare === 'none') {
            return [null, null];
        }

        [$s, $e] = $this->window($tz);
        $start = CarbonImmutable::parse($s);
        $end   = CarbonImmutable::parse($e);

        if ($this->compare === 'last_year') {
            return [$start->subYear()->toDateString(), $end->subYear()->toDateString()];
        }

        // previous: equal-length window ending the day before the period starts.
        $len      = $start->diffInDays($end) + 1;
        $prevEnd  = $start->subDay();
        $prevStart = $prevEnd->subDays($len - 1);

        return [$prevStart->toDateString(), $prevEnd->toDateString()];
    }

    /**
     * REV2 R3: [first, last] day of the COMPARE month, resolved against `month`
     * (the base month) — `compareMonth` explicit ('Custom') wins, else derived
     * from `compare` (previous = month-1, last_year = month-12). Unlike
     * monthWindow(), a compare month is allowed to be incomplete-in-the-future
     * relative to "today" in the trivial sense (it's always <= the base month,
     * and the base month is already validated complete by the caller), but it
     * still must actually be a SYNCED month — that's a data question the caller
     * answers (missing != zero: no data for the compare month means null
     * deltas, not a fabricated comparison), not something this filter object
     * can know.
     *
     * @return array{0: string, 1: string}|null null when there is no base month to compare against
     */
    public function compareMonthWindow(string $tz): ?array
    {
        if ($this->month === null) {
            return null;
        }

        $base = CarbonImmutable::parse($this->month . '-01', $tz);

        $target = $this->compareMonth !== null
            ? CarbonImmutable::parse($this->compareMonth . '-01', $tz)
            : ($this->compare === 'last_year' ? $base->subYear() : $base->subMonth());

        $start = $target->startOfMonth()->startOfDay();
        $end   = $start->endOfMonth()->startOfDay();

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Custom day-range mode (Kanwar, 2026-07-17 — "possibility to filter by
     * custom ranges... compare the first 2 weeks of the month year over year").
     * Active only when period='custom' AND both from/to are set; otherwise the
     * MoM report stays in its normal whole-month mode. One predicate so every
     * section and the shell agree on when a range is in force.
     */
    public function isCustomRange(): bool
    {
        return $this->period === 'custom' && $this->from !== null && $this->to !== null;
    }

    /**
     * A section's PRIMARY window: the custom day range when one is active
     * (window() already clamps `to` to yesterday so a partial today is never
     * shown), else the selected month via monthWindow() — null when that month
     * is unset/incomplete. Every range-compatible section reads THIS instead of
     * monthWindow() so a custom range flows through without each re-deriving it;
     * month mode is byte-for-byte the old monthWindow() behaviour.
     *
     * @return array{0: string, 1: string}|null
     */
    public function activeWindow(string $tz): ?array
    {
        if ($this->isCustomRange()) {
            [$s, $e] = $this->window($tz);

            return $s <= $e ? [$s, $e] : null; // a `from` after the clamped `to` is not a window
        }

        return $this->monthWindow($tz);
    }

    /**
     * The comparison window that matches activeWindow(). For a custom range the
     * default is the SAME calendar dates one year earlier (YoY — the whole point
     * of the feature), with compare='previous' giving the equal-length window
     * immediately before, and compare='none' giving nothing. For month mode it
     * is the existing compareMonthWindow(). Returns null (not [null,null]) when
     * there is nothing to compare, matching compareMonthWindow()'s contract so
     * callers keep their `!== null` guard unchanged.
     *
     * @return array{0: string, 1: string}|null
     */
    public function activeComparisonWindow(string $tz): ?array
    {
        if (! $this->isCustomRange()) {
            return $this->compareMonthWindow($tz);
        }

        if ($this->compare === 'none') {
            return null;
        }

        [$s, $e] = $this->window($tz);
        $start = CarbonImmutable::parse($s);
        $end   = CarbonImmutable::parse($e);

        if ($this->compare === 'previous') {
            $len       = $start->diffInDays($end) + 1;
            $prevEnd   = $start->subDay();
            $prevStart = $prevEnd->subDays($len - 1);

            return [$prevStart->toDateString(), $prevEnd->toDateString()];
        }

        // 'previous'/'last_year' both handled; anything else (defaulted) → YoY.
        return [$start->subYear()->toDateString(), $end->subYear()->toDateString()];
    }

    /**
     * Display label for the active window: the literal day range in range mode
     * (e.g. "2026-06-01 – 2026-06-14"), else the 'Y-m' month (or null when
     * unset). The shell renders this so the header names exactly what's shown.
     */
    public function activeWindowLabel(string $tz): ?string
    {
        if ($this->isCustomRange()) {
            [$s, $e] = $this->window($tz);

            return $s . ' – ' . $e;
        }

        return $this->month;
    }

    public function periodLabel(): string
    {
        return match ($this->period) {
            'last7'  => 'Last 7 days',
            'mtd'    => 'Month to date',
            'custom' => 'Custom range',
            default  => 'Last 30 days',
        };
    }

    public function comparisonLabel(): ?string
    {
        return match ($this->compare) {
            'previous'  => 'vs previous period',
            'last_year' => 'vs last year',
            default     => null,
        };
    }
}
