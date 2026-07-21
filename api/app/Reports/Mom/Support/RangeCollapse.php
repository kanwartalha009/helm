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
     * Week-on-week matrix (Kanwar, 2026-07-20): one column per ISO week across
     * the custom range plus a Total column. Uses the SAME payload shape as the
     * collapse (columns + {v,f} rows + optional footer) so the existing
     * RangeCollapseTable renders it with no new frontend code. Rows carry their
     * own format so a mixed matrix (revenue money-rows + a ROAS ratio-row) is
     * fine; the footer is passed in by the caller (a sum for all-money group
     * matrices, null where a footer would be meaningless — you can't sum ROAS).
     *
     * @param array<int, array{label: string, week?: int}> $weeks one entry per week column, in order (label + ISO week number)
     * @param array<int, array{label: string, format: string, cells: array<int, float|int|null>, total: float|int|null}> $rows
     * @param array<int, array{v: mixed, f: string}>|null $footer
     */
    public static function weekly(string $firstColLabel, array $weeks, array $rows, ?array $footer, string $title, ?string $note = null): array
    {
        $weekLabels  = array_map(static fn (array $w): string => (string) ($w['label'] ?? ''), $weeks);
        $weekHeaders = array_map(static fn (array $w): array => [
            'week'  => isset($w['week']) ? (int) $w['week'] : null,
            'label' => (string) ($w['label'] ?? ''),
        ], $weeks);

        $columns = array_merge([$firstColLabel], $weekLabels, ['Total']);

        $outRows = [];
        foreach ($rows as $r) {
            $cells = [self::cell($r['label'], 'text')];
            foreach ($r['cells'] as $v) {
                $cells[] = self::cell($v, $r['format']);
            }
            $cells[] = self::cell($r['total'], $r['format']);
            $outRows[] = $cells;
        }

        return [
            'title'        => $title,
            'rangeLabel'   => null,
            'compareLabel' => null,
            // `weekly` + `weekHeaders` tell RangeCollapseTable to render two-line
            // "W18 / 1–3 May" headers and heat-colour each week cell week-over-week
            // (like the month matrices). `columns` stays as a flat-string fallback.
            'weekly'       => true,
            'weekHeaders'  => $weekHeaders,
            'columns'      => $columns,
            'rows'         => $outRows,
            'footer'       => $footer,
            'note'         => $note,
        ];
    }

    /**
     * Build a per-week + grand totals footer for an all-money group matrix
     * (revenue by tier/country/category/product) — sums each week column and the
     * Total column across the given money rows.
     *
     * @param array<int, array{label: string, format: string, cells: array<int, float|int|null>, total: float|int|null}> $rows
     * @return array<int, array{v: mixed, f: string}>
     */
    public static function weeklyMoneyFooter(array $rows): array
    {
        $weekCount = $rows === [] ? 0 : count($rows[0]['cells']);
        $weekTotals = array_fill(0, $weekCount, 0.0);
        $grand = 0.0;

        foreach ($rows as $r) {
            foreach ($r['cells'] as $i => $v) {
                if (is_numeric($v)) {
                    $weekTotals[$i] += (float) $v;
                }
            }
            if (is_numeric($r['total'])) {
                $grand += (float) $r['total'];
            }
        }

        $footer = [self::cell('Total', 'text')];
        foreach ($weekTotals as $t) {
            $footer[] = self::cell(round($t, 2), 'money');
        }
        $footer[] = self::cell(round($grand, 2), 'money');

        return $footer;
    }

    /**
     * Assemble an all-money week-on-week matrix from groups (tiers, countries,
     * categories, products): each group is one money row of weekly revenue, a
     * summed footer, sorted by range total (biggest first). The one shared path
     * S4/S5/S7/S8 use for their weekly view.
     *
     * @param array<int, array{label: string, week?: int}> $weeks
     * @param array<int, array{label: string, weekly: array<int, float|int|null>, total: float|int|null}> $groups
     */
    public static function weeklyRevenueByGroup(string $firstColLabel, array $weeks, array $groups, string $title, ?string $note = null): array
    {
        usort($groups, static fn (array $a, array $b): int => (float) ($b['total'] ?? 0) <=> (float) ($a['total'] ?? 0));

        $rows = [];
        foreach ($groups as $g) {
            $rows[] = ['label' => $g['label'], 'format' => 'money', 'cells' => $g['weekly'], 'total' => $g['total']];
        }

        $footer = self::weeklyMoneyFooter($rows);

        return self::weekly($firstColLabel, $weeks, $rows, $footer, $title, $note);
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
