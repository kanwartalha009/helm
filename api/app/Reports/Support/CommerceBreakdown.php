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
    // Trajectory thresholds (Δ% vs the comparison window). These classify every
    // market/product into the agency report's status badges + region matrix.
    // Defaults — tune once Bosco confirms his banding (open item #16).
    private const DEAD    = -30.0;  // Δ% ≤ this → dead zone
    private const WOUNDED = -10.0;  // DEAD < Δ% ≤ this → wounded
    private const GROWING = 10.0;   // Δ% ≥ this → recovered / growing
    // between WOUNDED and GROWING → holding/stable.

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

        $hasCompare = $cStart !== null && $cEnd !== null;
        $prev = $hasCompare ? $this->aggregate($brandId, $dimensionType, $cStart, $cEnd, $usd) : [];

        // Enrich EVERY row with its comparison + trajectory first, so the matrix
        // counts span the full market set (not just the visible top N). Section
        // totals here — shares are within the section, summing to 100%.
        $totalRev = 0.0;
        $totalOrders = 0;
        foreach ($cur as $key => &$row) {
            $totalRev    += $row['revenue'];
            $totalOrders += $row['orders'];
            $prevRev      = $prev[$key]['revenue'] ?? null;
            $row['previous'] = $prevRev !== null ? round((float) $prevRev, 2) : null;
            $row['deltaPct'] = $this->pct($row['revenue'], $prevRev);
            $row['trend']    = $hasCompare ? $this->trend($row['deltaPct'], $prevRev, $row['revenue']) : null;
        }
        unset($row);

        // Rank by revenue, split into the top N + an "other" rollup of the tail.
        usort($cur, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
        $top  = array_slice($cur, 0, $limit);
        $tail = array_slice($cur, $limit);

        $rows = [];
        foreach ($top as $row) {
            $rows[] = [
                'key'      => $row['key'],
                'label'    => $row['label'],
                'revenue'  => round($row['revenue'], 2),
                'orders'   => $row['orders'],
                'aov'      => $row['orders'] > 0 ? round($row['revenue'] / $row['orders'], 2) : null,
                'share'    => $totalRev > 0 ? round($row['revenue'] / $totalRev, 4) : null,
                'previous' => $row['previous'],
                'deltaPct' => $row['deltaPct'],
                'trend'    => $row['trend'],
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
            'rows'   => $rows,
            'other'  => $other,
            'total'  => ['revenue' => round($totalRev, 2), 'orders' => $totalOrders],
            // Counts + sample movers per trajectory bucket across ALL rows —
            // drives the region status matrix. Null when there's no comparison
            // (every row would be "new", which says nothing).
            'matrix' => $hasCompare ? $this->matrix($cur) : null,
        ];
    }

    /** Classify a row's trajectory from its Δ%, prior revenue and current revenue. */
    private function trend(?float $deltaPct, float|int|null $previous, float $revenue): string
    {
        if ($previous === null) {
            return 'new';            // no prior revenue → first-time
        }
        if ($deltaPct === null) {
            // Prior revenue was zero: only call it "growing" when there IS
            // current revenue — 0 → 0 is flat, never a growth badge.
            return $revenue > 0 ? 'growing' : 'stable';
        }
        if ($deltaPct <= self::DEAD) {
            return 'dead';
        }
        if ($deltaPct <= self::WOUNDED) {
            return 'wounded';
        }
        if ($deltaPct >= self::GROWING) {
            return 'growing';
        }

        return 'stable';
    }

    /**
     * Bucket counts + top-3 sample movers (by revenue) for the four matrix
     * cells. "stable" is intentionally excluded — the matrix highlights change.
     *
     * @param array<int, array<string, mixed>> $rows  full set, already enriched
     * @return array<int, array<string, mixed>>
     */
    private function matrix(array $rows): array
    {
        $buckets = ['dead' => [], 'wounded' => [], 'new' => [], 'growing' => []];
        foreach ($rows as $r) {
            $t = $r['trend'] ?? null;
            if ($t !== null && isset($buckets[$t])) {
                $buckets[$t][] = [
                    'label'    => $r['label'],
                    'deltaPct' => $r['deltaPct'],
                    'revenue'  => round((float) $r['revenue'], 2),
                ];
            }
        }

        $out = [];
        foreach ($buckets as $bucket => $items) {
            usort($items, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
            $out[] = ['bucket' => $bucket, 'count' => count($items), 'samples' => array_slice($items, 0, 3)];
        }

        return $out;
    }

    /**
     * Sum revenue + orders per dimension_key over the window, in display
     * currency. Revenue follows D-005 (total_sales with refunds added back —
     * the report's headline revenue basis, so the sections reconcile to it),
     * × the stored fx snapshot when USD is requested — never converted at read
     * time without the stored rate (spec rule 7).
     *
     * @return array<string, array{key: string, label: string, revenue: float, orders: int}>
     */
    private function aggregate(int $brandId, string $dimensionType, string $start, string $end, bool $usd): array
    {
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';
        $rev    = $usd ? "{$revCol} * COALESCE(fx_rate_to_usd, 1)" : $revCol;

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
