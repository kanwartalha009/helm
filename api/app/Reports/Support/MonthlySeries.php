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
        ?array $groupMap = null,
        array $groupLabels = [],
        ?callable $keyMap = null,
    ): ?array {
        if ($months === []) {
            return null;
        }

        // The full span the trailing months cover, PLUS the same months one year
        // earlier so each segment can carry a YoY figure in a single pull.
        $first = CarbonImmutable::parse($months[0] . '-01');
        $last  = CarbonImmutable::parse(end($months) . '-01')->endOfMonth();
        $rows  = $this->aggregate($brandId, $dimensionType, $first->subYear()->toDateString(), $last->toDateString(), $usd, $keyMap);
        if ($rows === []) {
            return null;
        }

        // Which trailing months the brand actually has commerce rows for. A month
        // absent from EVERY segment wasn't synced (backfill hasn't reached it) — so
        // it renders as "—", never €0 (spec rule 9: missing ≠ zero). A month that
        // IS synced but where a given segment has no row is a real zero for that
        // segment and stays 0.
        $syncedMonths = [];
        foreach ($rows as $r) {
            foreach (array_keys($r['byMonth']) as $ym) {
                $syncedMonths[(string) $ym] = true;
            }
        }

        // Optional fold: remap each key into a group (e.g. country → market/tier)
        // before ranking, so one query drives both the country and market tables.
        if ($groupMap !== null) {
            $rows = $this->regroup($rows, $groupMap, $groupLabels);
        }

        // The Y-m keys for the same-month-last-year YoY total (whole trailing set,
        // shifted back a year) so "Rev 25" sums the comparable prior months.
        $yoyMonths = array_map(static fn (string $m): string => CarbonImmutable::parse($m . '-01')->subYear()->format('Y-m'), $months);

        $segments = [];
        foreach ($rows as $key => $r) {
            $byMonth = [];
            $curTotal = 0.0;
            foreach ($months as $m) {
                if (! isset($syncedMonths[$m])) {
                    $byMonth[$m] = null; // unsynced month → "—"
                    continue;
                }
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
            $otherYoy   = 0.0;
            // Sum the tail per month so the "Other" row shows a real trend (it can
            // be the majority of revenue, e.g. 2,600+ products). A month stays null
            // only if EVERY tail segment is unsynced for it, so "—" still means
            // not-synced, never a hidden zero.
            $otherByMonth = array_fill_keys($months, null);
            foreach ($tail as $s) {
                $otherTotal += $s['total'];
                $otherYoy   += $s['yoyTotal'];
                foreach ($months as $m) {
                    $v = $s['byMonth'][$m] ?? null;
                    if ($v !== null) {
                        $otherByMonth[$m] = ($otherByMonth[$m] ?? 0) + $v;
                    }
                }
            }
            foreach ($months as $m) {
                if ($otherByMonth[$m] !== null) {
                    $otherByMonth[$m] = round($otherByMonth[$m], 2);
                }
            }
            $other = [
                'byMonth'  => $otherByMonth,
                'total'    => round($otherTotal, 2),
                'yoyTotal' => round($otherYoy, 2),
                'deltaYoY' => $this->pct($otherTotal, $otherYoy),
                'share'    => $grandTotal > 0 ? round($otherTotal / $grandTotal, 4) : null,
                'count'    => count($tail),
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
     * Raw per-key monthly revenue matrix over [start, end] — the un-ranked, un-
     * grouped aggregate. Used where a section needs the values keyed by segment
     * (e.g. ROAS-by-country pairs this revenue with Meta spend per country/month).
     *
     * @return array<string, array{label: string, byMonth: array<string, float>, ordersTotal: int}>
     */
    public function rawByMonth(int $brandId, string $dimensionType, string $start, string $end, bool $usd, ?callable $keyMap = null): array
    {
        return $this->aggregate($brandId, $dimensionType, $start, $end, $usd, $keyMap);
    }

    /**
     * Per dimension_key: label + revenue by Y-m across the span, and the total
     * orders. One grouped query (dimension_key × month) pivoted in PHP.
     *
     * @return array<string, array{label: string, byMonth: array<string, float>, ordersTotal: int}>
     */
    private function aggregate(int $brandId, string $dimensionType, string $start, string $end, bool $usd, ?callable $keyMap = null): array
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

        // Optional key canonicalisation (e.g. Shopify country NAME → ISO-2), so
        // rows fold onto the same keys Meta spend and the region map use. Unmapped
        // keys keep their original form (they still show; they just won't join).
        if ($keyMap !== null) {
            $out = $this->remapKeys($out, $keyMap);
        }

        return $out;
    }

    /**
     * Fold aggregate rows onto canonical keys via a callable (raw key → new key,
     * or null to leave it untouched). Rows landing on the same key merge (byMonth
     * summed, orders summed, first label kept). Used to reconcile Shopify country
     * NAMES to the ISO-2 codes Meta + config/country_regions use.
     *
     * @param array<string, array{label: string, byMonth: array<string, float>, ordersTotal: int}> $rows
     * @return array<string, array{label: string, byMonth: array<string, float>, ordersTotal: int}>
     */
    private function remapKeys(array $rows, callable $keyMap): array
    {
        $out = [];
        foreach ($rows as $key => $r) {
            $canonical = (string) ($keyMap((string) $key) ?? $key);
            if (! isset($out[$canonical])) {
                $out[$canonical] = ['label' => $r['label'], 'byMonth' => [], 'ordersTotal' => 0];
            }
            foreach ($r['byMonth'] as $ym => $v) {
                $out[$canonical]['byMonth'][$ym] = ($out[$canonical]['byMonth'][$ym] ?? 0) + $v;
            }
            $out[$canonical]['ordersTotal'] += $r['ordersTotal'];
        }

        return $out;
    }

    /**
     * Fold per-key aggregate rows into groups (e.g. country → market) using a
     * key→group map; unmapped keys land in "other" so groups reconcile to 100%.
     * Preserves the full byMonth span (incl. last-year months) so YoY still holds.
     *
     * @param array<string, array{label: string, byMonth: array<string, float>, ordersTotal: int}> $rows
     * @param array<string, string> $map     UPPER-cased key => group key
     * @param array<string, string> $labels  group key => display label
     * @return array<string, array{label: string, byMonth: array<string, float>, ordersTotal: int}>
     */
    private function regroup(array $rows, array $map, array $labels): array
    {
        $out = [];
        foreach ($rows as $key => $r) {
            $g = $map[strtoupper((string) $key)] ?? 'other';
            $out[$g] ??= ['label' => (string) ($labels[$g] ?? ucfirst($g)), 'byMonth' => [], 'ordersTotal' => 0];
            foreach ($r['byMonth'] as $ym => $v) {
                $out[$g]['byMonth'][$ym] = ($out[$g]['byMonth'][$ym] ?? 0) + $v;
            }
            $out[$g]['ordersTotal'] += $r['ordersTotal'];
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
