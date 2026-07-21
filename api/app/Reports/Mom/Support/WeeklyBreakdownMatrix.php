<?php

declare(strict_types=1);

namespace App\Reports\Mom\Support;

use App\Reports\Support\CommerceBreakdown;
use Carbon\CarbonImmutable;

/**
 * Week-on-week matrix scaffolding for the commerce-breakdown sections S7
 * (categories) and S8 (best sellers) (Kanwar, 2026-07-21). Produces the SAME
 * row shape the monthly matrix (CommerceBreakdown::monthlyMatrix) emits — top-N
 * rows each carrying a per-period `monthly[]`, window `revenue`, `share`
 * (percent), `deltaMoMPct` and `deltaYoYPct` — but with ISO weeks as the periods
 * instead of months, plus the `months` / `monthLabels` / `weekHeaders` period
 * scaffolding the shared frontend renderers read. Each section layers its own
 * stock join on top of the returned rows.
 *
 * Top-N is ranked by the RANGE total (like month mode ranks by window total).
 * Each week's per-key revenue is fetched with a wide limit so a group that isn't
 * that week's own top-N still lands in its cell rather than vanishing. The tail
 * folds into `other` (carried, not rendered as a row — matching month mode).
 */
final class WeeklyBreakdownMatrix
{
    /** Wide per-week fetch so any range-top key resolves to its own weekly cell. */
    private const WIDE_LIMIT = 500;

    /**
     * @param array{0: string, 1: string} $range brand-tz [start, end] date strings
     * @param array<int, array{start:string,end:string,label:string,week:int}> $weeks
     * @return array{months: array<int,string>, monthLabels: array<int,string>, weekHeaders: array<int,array{week:int,label:string}>, total: mixed, other: mixed, rows: array<int, array<string, mixed>>}|null
     */
    public static function build(
        CommerceBreakdown $bd,
        int $brandId,
        string $dimension,
        array $range,
        array $weeks,
        bool $usd,
        int $limit,
        string $tz,
    ): ?array {
        $rangeBd = $bd->forDimension($brandId, $dimension, $range[0], $range[1], null, null, $usd, $limit);
        if ($rangeBd === null) {
            return null;
        }
        $rangeTotal = (float) ($rangeBd['total']['revenue'] ?? 0.0);

        // Same range one year earlier, wide, for ΔYoY per key.
        $priorStart = CarbonImmutable::parse($range[0], $tz)->subYear()->toDateString();
        $priorEnd   = CarbonImmutable::parse($range[1], $tz)->subYear()->toDateString();
        $priorBd    = $bd->forDimension($brandId, $dimension, $priorStart, $priorEnd, null, null, $usd, self::WIDE_LIMIT);
        $priorMap   = [];
        foreach (($priorBd['rows'] ?? []) as $r) {
            if (isset($r['key'])) {
                $priorMap[$r['key']] = (float) $r['revenue'];
            }
        }

        // Wide per-week per-key revenue.
        $perWeekMap = [];
        foreach ($weeks as $i => $w) {
            $wb = $bd->forDimension($brandId, $dimension, $w['start'], $w['end'], null, null, $usd, self::WIDE_LIMIT);
            $map = [];
            foreach (($wb['rows'] ?? []) as $r) {
                if (isset($r['key'])) {
                    $map[$r['key']] = (float) $r['revenue'];
                }
            }
            $perWeekMap[$i] = $map;
        }

        $rows = [];
        foreach (($rangeBd['rows'] ?? []) as $r) {
            $key = $r['key'] ?? null;
            $cells = [];
            foreach ($weeks as $i => $_) {
                $cells[] = ($key !== null && array_key_exists($key, $perWeekMap[$i])) ? round($perWeekMap[$i][$key], 2) : null;
            }
            $rev = (float) $r['revenue'];

            $rows[] = [
                'key'      => $key,
                'label'    => $r['label'],
                'monthly'  => $cells, // one cell per ISO week, aligned to `months`
                'revenue'  => round($rev, 2),
                'orders'   => $r['orders'] ?? null,
                'share'    => $rangeTotal > 0.0 ? round($rev / $rangeTotal * 100, 1) : null,
                'deltaMoMPct' => WeekSplit::lastWeekDelta($cells),
                'deltaYoYPct' => $key !== null ? self::pct($rev, $priorMap[$key] ?? null) : null,
            ];
        }

        $periods = WeekSplit::periods($weeks);

        return [
            'months'      => $periods['months'],
            'monthLabels' => $periods['monthLabels'],
            'weekHeaders' => $periods['weekHeaders'],
            'total'       => $rangeBd['total'] ?? null,
            'other'       => $rangeBd['other'] ?? null,
            'rows'        => $rows,
        ];
    }

    private static function pct(?float $cur, ?float $prev): ?float
    {
        if ($cur === null || $prev === null || $prev === 0.0) {
            return null;
        }

        return round(($cur - $prev) / $prev * 100, 1);
    }
}
