<?php

declare(strict_types=1);

namespace App\Reports\Support;

use App\Models\CommerceDailyMetric;
use Carbon\CarbonImmutable;

/**
 * Turns commerce_daily_metrics into a MONTH-OVER-MONTH series for one dimension
 * (country / product / category): each segment's revenue + orders per calendar
 * month across a trailing range, plus a same-month-last-year value for the YoY
 * column. This is the shape the monthly client report's heatmap tables need —
 * distinct from CommerceBreakdown, which does a single window vs one comparison.
 *
 * Missing ≠ zero (spec rule 9): returns null when the brand has no commerce rows
 * for the dimension across the range, so the report omits the section until the
 * commerce backfill has landed. Currency follows the report — native, or ×the
 * stored fx snapshot when USD is requested (spec rule 7), never converted at read
 * time without the stored rate.
 */
final class MonthlySeries
{
    /**
     * @param array<int, string> $months  the trailing Y-m columns, chronological
     *                                     (e.g. ['2026-02',…,'2026-07'])
     * @return array<string, mixed>|null   { months, rows[], total{byMonth,…} }
     */
    public function forDimension(
        int $brandId,
        string $dimensionType,
        array $months,
        bool $usd,
        int $limit = 8,
    ): ?array {
        if ($months === []) {
            return null;
        }

        // The full span the trailing months cover, PLUS the same months one year
        // earlier so each segment can carry a YoY figure in a single pull.
        $first = CarbonImmutable::parse($months[0] . '-01');
        $last  = CarbonImmutable::parse(end($months) . '-01')->endOfMonth();
        $rows  = $this->aggregate($brandId, $dimensionType, $first->subYear()->toDateString(), $last->toDateString(), $usd);
        if ($rows === []) {
            return null;
        }

        // The Y-m keys for the same-month-last-year YoY total (whole trailing set,
        // shifted back a year) so "Rev 25" sums the comparable prior months.
        $yoyMonths = array_map(static fn (string $m): string => CarbonImmutable::parse($m . '-01')->subYear()->format('Y-m'), $months);

        $segments = [];
        foreach ($rows as $key => $r) {
            $byMonth = [];
            $curTotal = 0.0;
            foreach ($months as $m) {
                $v = round((float) ($r['byMonth'][$m] ?? 0), 2);
                $byMonth[$m] = $v;
                $curTotal   += $v;
            }
            $yoyTotal = 0.0;
            foreach ($yoyMonths as $m) {
                $yoyTotal += (float) ($r['byMonth'][$m] ?? 0);
            }

            $segments[] = [
                'key'       => $key,
                'label'     => $r['label'],
                'byMonth'   => $byMonth,          // Y-m => revenue
                'total'     => round($curTotal, 2),
                'yoyTotal'  => round($yoyTotal, 2),
                'deltaYoY'  => $this->pct($curTotal, $yoyTotal),
                'orders'    => $r['ordersTotal'],
            ];
        }

        // Rank by trailing-range revenue; top N + an "other" rollup of the tail.
        usort($segments, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $top  = array_slice($segments, 0, $limit);
        $tail = array_slice($segments, $limit);

        $grandTotal = 0.0;
        foreach ($segments as $s) {
            $grandTotal += $s['total'];
        }
        foreach ($top as &$s) {
            $s['share'] = $grandTotal > 0 ? round($s['total'] / $grandTotal, 4) : null;
        }
        unset($s);

        $other = null;
        if ($tail !== []) {
            $otherTotal = 0.0;
            foreach ($tail as $s) {
                $otherTotal += $s['total'];
            }
            $other = [
                'total' => round($otherTotal, 2),
                'share' => $grandTotal > 0 ? round($otherTotal / $grandTotal, 4) : null,
                'count' => count($tail),
            ];
        }

        return [
            'months' => $months,
            'rows'   => $top,
            'other'  => $other,
            'total'  => round($grandTotal, 2),
        ];
    }

    /**
     * Per dimension_key: label + revenue by Y-m across the span, and the total
     * orders. One grouped query (dimension_key × month) pivoted in PHP.
     *
     * @return array<string, array{label: string, byMonth: array<string, float>, ordersTotal: int}>
     */
    private function aggregate(int $brandId, string $dimensionType, string $start, string $end, bool $usd): array
    {
        $rev = $usd ? 'total_sales * COALESCE(fx_rate_to_usd, 1)' : 'total_sales';

        $rows = CommerceDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', $dimensionType)
            ->whereBetween('date', [$start, $end])
            ->groupByRaw("dimension_key, DATE_FORMAT(date, '%Y-%m')")
            ->selectRaw("dimension_key,
                MAX(dimension_label) AS label,
                DATE_FORMAT(date, '%Y-%m') AS ym,
                COALESCE(SUM({$rev}), 0) AS revenue,
                COALESCE(SUM(orders), 0) AS orders")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->dimension_key;
            if ($key === '') {
                continue;
            }
            $out[$key] ??= ['label' => (string) ($r->label ?? $key), 'byMonth' => [], 'ordersTotal' => 0];
            $out[$key]['byMonth'][(string) $r->ym] = (float) $r->revenue;
            $out[$key]['ordersTotal']             += (int) $r->orders;
        }

        return $out;
    }

    private function pct(float|int|null $cur, float|int|null $prev): ?float
    {
        if ($cur === null || $prev === null || (float) $prev === 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $prev) / (float) $prev * 100, 1);
    }
}
