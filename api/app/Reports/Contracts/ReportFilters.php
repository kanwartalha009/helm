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
