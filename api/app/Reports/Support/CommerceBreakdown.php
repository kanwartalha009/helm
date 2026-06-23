<?php

declare(strict_types=1);

namespace App\Reports\Support;

use App\Models\CommerceDailyMetric;

/**
 * Turns commerce_daily_metrics (slice 2.1) into a render-ready top-N breakdown
 * for one dimension — by country, product, or category — with each row's
 * comparison-window revenue and delta. Shared by the report types so the query
 * logic lives in one place.
 *
 * Returns null when the brand has no commerce rows for the dimension in the
 * window: the caller omits the section entirely (missing ≠ zero, spec rule 9),
 * so the report renders cleanly before the backfill has run and lights up the
 * moment it has.
 */
final class CommerceBreakdown
{
    /**
     * @return array<string, mixed>|null
     */
    public function forDimension(
        int $brandId,
        string $dimensionType,
        string $start,
        string $end,
        ?string $cStart,
        ?string $cEnd,
        bool $usd,
        int $limit = 8,
    ): ?array {
        $cur = $this->aggregate($brandId, $dimensionType, $start, $end, $usd);
        if ($cur === []) {
            return null;
        }

        $prev = ($cStart !== null && $cEnd !== null)
            ? $this->aggregate($brandId, $dimensionType, $cStart, $cEnd, $usd)
            : [];

        // Section totals — shares are within the section, so they sum to 100%
        // and never depend on the brand-level KPI revenue (which is computed
        // from a different grain).
        $totalRev = 0.0;
        $totalOrders = 0;
        foreach ($cur as $row) {
            $totalRev += $row['revenue'];
            $totalOrders += $row['orders'];
        }

        // Rank by revenue, split into the top N + an "other" rollup of the tail.
        usort($cur, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
        $top  = array_slice($cur, 0, $limit);
        $tail = array_slice($cur, $limit);

        $rows = [];
        foreach ($top as $row) {
            $prevRev = $prev[$row['key']]['revenue'] ?? null;
            $rows[] = [
                'key'      => $row['key'],
                'label'    => $row['label'],
                'revenue'  => round($row['revenue'], 2),
                'orders'   => $row['orders'],
                'aov'      => $row['orders'] > 0 ? round($row['revenue'] / $row['orders'], 2) : null,
                'share'    => $totalRev > 0 ? round($row['revenue'] / $totalRev, 4) : null,
                'previous' => $prevRev !== null ? round((float) $prevRev, 2) : null,
                'deltaPct' => $this->pct($row['revenue'], $prevRev),
            ];
        }

        $other = null;
        if ($tail !== []) {
            $otherRev = 0.0;
            $otherOrders = 0;
            foreach ($tail as $row) {
                $otherRev    += $row['revenue'];
                $otherOrders += $row['orders'];
            }
            $other = [
                'revenue' => round($otherRev, 2),
                'orders'  => $otherOrders,
                'share'   => $totalRev > 0 ? round($otherRev / $totalRev, 4) : null,
                'count'   => count($tail),
            ];
        }

        return [
            'rows'  => $rows,
            'other' => $other,
            'total' => ['revenue' => round($totalRev, 2), 'orders' => $totalOrders],
        ];
    }

    /**
     * Sum revenue + orders per dimension_key over the window, in display
     * currency. Revenue is total_sales (the report's headline revenue) × the
     * stored fx snapshot when USD is requested — never converted at read time
     * without the stored rate (spec rule 7).
     *
     * @return array<string, array{key: string, label: string, revenue: float, orders: int}>
     */
    private function aggregate(int $brandId, string $dimensionType, string $start, string $end, bool $usd): array
    {
        $rev = $usd ? 'total_sales * COALESCE(fx_rate_to_usd, 1)' : 'total_sales';

        $rows = CommerceDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', $dimensionType)
            ->whereBetween('date', [$start, $end])
            ->groupBy('dimension_key')
            ->selectRaw("dimension_key,
                MAX(dimension_label) AS label,
                COALESCE(SUM({$rev}), 0) AS revenue,
                COALESCE(SUM(orders), 0) AS orders")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->dimension_key;
            if ($key === '') {
                continue;
            }
            $out[$key] = [
                'key'     => $key,
                'label'   => (string) ($r->label ?? $key),
                'revenue' => (float) $r->revenue,
                'orders'  => (int) $r->orders,
            ];
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
