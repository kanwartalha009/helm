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
    ) {}

    /** @param array<string, mixed> $p query params */
    public static function fromArray(array $p): self
    {
        $period  = in_array($p['period'] ?? null, ['last7', 'last30', 'mtd', 'custom'], true) ? (string) $p['period'] : 'last30';
        $compare = in_array($p['compare'] ?? null, ['previous', 'last_year', 'none'], true) ? (string) $p['compare'] : 'previous';
        $ymd     = static fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        return new self(
            period:  $period,
            from:    $ymd($p['from'] ?? null),
            to:      $ymd($p['to'] ?? null),
            compare: $compare,
            usd:     strtoupper((string) ($p['currency'] ?? '')) === 'USD',
        );
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
