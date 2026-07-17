<?php

declare(strict_types=1);

namespace App\Reports\Mom\Support;

use Carbon\CarbonImmutable;

/**
 * Custom date ranges (Kanwar, 2026-07-17): when a sub-month range is active the
 * month-by-month matrix sections can't show monthly columns, so they COLLAPSE to
 * a single "selected range vs the same range last year" table. This helper builds
 * the ONE uniform payload the SPA's RangeCollapseTable renders — header labels
 * plus rows of {v,f} cells (f = money|pct|ratio|delta|count|text) — so every
 * matrix (tiers, countries, categories, products, ROAS, the financial matrix)
 * collapses through a single render path rather than each inventing its own.
 */
final class RangeCollapse
{
    /** "1 Jun 2026 – 14 Jun 2026" for a window's start/end date strings. */
    public static function label(string $start, string $end): string
    {
        return CarbonImmutable::parse($start)->isoFormat('D MMM YYYY') . ' – ' . CarbonImmutable::parse($end)->isoFormat('D MMM YYYY');
    }

    /** @return array{v: mixed, f: string} one formatted cell */
    public static function cell(mixed $v, string $f): array
    {
        return ['v' => $v, 'f' => $f];
    }

    /** Percent change v vs c, or null when it can't be honestly computed. */
    public static function delta(float|int|null $v, float|int|null $c): ?float
    {
        if ($v === null || $c === null || (float) $c === 0.0) {
            return null;
        }

        return round(((float) $v - (float) $c) / (float) $c * 100, 1);
    }

    /**
     * Assemble the collapse payload: header labels + rows of {v,f} cells, an
     * optional totals footer, and an optional honesty note.
     *
     * @param array<int, string> $columns
     * @param array<int, array<int, array{v: mixed, f: string}>> $rows
     * @param array<int, array{v: mixed, f: string}>|null $footer
     */
    public static function table(string $rangeLabel, string $compareLabel, array $columns, array $rows, ?array $footer = null, ?string $note = null): array
    {
        return [
            'title'        => 'Selected range vs the same range last year',
            'rangeLabel'   => $rangeLabel,
            'compareLabel' => $compareLabel,
            'columns'      => $columns,
            'rows'         => $rows,
            'footer'       => $footer,
            'note'         => $note,
        ];
    }

    /**
     * Revenue-by-group collapse (tiers, countries, categories, products): each
     * group's revenue over the range vs the same range last year, its Δ YoY and
     * its share of the range total. Sorted by range revenue, biggest first.
     *
     * @param array<int, array{label: string, value: float, compare: float|null}> $groups
     */
    public static function revenueByGroup(string $rangeLabel, string $compareLabel, array $groups, ?string $note = null): array
    {
        usort($groups, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        $total = 0.0;
        foreach ($groups as $g) {
            $total += $g['value'];
        }

        $rows = [];
        foreach ($groups as $g) {
            $rows[] = [
                self::cell($g['label'], 'text'),
                self::cell(round($g['value'], 2), 'money'),
                self::cell($g['compare'] !== null ? round($g['compare'], 2) : null, 'money'),
                self::cell(self::delta($g['value'], $g['compare']), 'delta'),
                self::cell($total > 0.0 ? round($g['value'] / $total * 100, 1) : null, 'pct'),
            ];
        }

        $footer = [
            self::cell('Total', 'text'),
            self::cell(round($total, 2), 'money'),
            self::cell(null, 'text'),
            self::cell(null, 'text'),
            self::cell($total > 0.0 ? 100.0 : null, 'pct'),
        ];

        return self::table($rangeLabel, $compareLabel, ['Segment', $rangeLabel, $compareLabel, 'Δ YoY', 'Share'], $rows, $footer, $note);
    }
}
