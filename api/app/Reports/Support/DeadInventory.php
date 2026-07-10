<?php

declare(strict_types=1);

namespace App\Reports\Support;

use App\Models\InventorySnapshot;
use Carbon\CarbonImmutable;

/**
 * Reads the latest inventory snapshot and surfaces dead / overstocked stock —
 * products or collections sitting on inventory that isn't selling — for the
 * report's dead-stock section (feature spec slice 2.1). Rules-driven from
 * ending units + units sold over the snapshot window; "cover days" is the
 * runway at the current sell rate.
 *
 * Returns null when the brand has no inventory snapshot yet (shopify:sync-
 * inventory hasn't run), so the report omits the section until data exists.
 */
final class DeadInventory
{
    private const SLOW_COVER_DAYS = 180.0; // > ~6 months of stock at current velocity → overstocked
    private const MIN_UNITS       = 1;     // ignore items with no stock on hand

    // "Dead" is RELATIVE to the brand's own velocity: an item is dead when its
    // window sales are ≤ 10% of the brand's MEDIAN units_sold (over stocked
    // items in the latest snapshot). [HELM DEFAULT — no published industry
    // standard; 'dead' scales with the brand's own velocity, e.g. median 10
    // units → dead = ≤1 unit; median <10 → threshold 0 → identical to the old
    // zero-sales rule.]
    private const DEAD_PCT_OF_MEDIAN = 10.0;

    /**
     * @return array<string, mixed>|null
     */
    public function forDimension(int $brandId, string $dimensionType, int $limit = 12): ?array
    {
        $capturedOn = InventorySnapshot::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', $dimensionType)
            ->max('captured_on');

        if ($capturedOn === null) {
            return null;
        }

        // Normalise to a plain Y-m-d before whereDate(): MySQL's DATE column
        // returns '2026-07-10' but sqlite (tests) stores the cast Carbon as
        // '2026-07-10 00:00:00', and whereDate() compares the raw string —
        // the un-normalised value silently matches zero rows there.
        $capturedDate = CarbonImmutable::parse((string) $capturedOn)->toDateString();

        $rows = InventorySnapshot::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', $dimensionType)
            ->whereDate('captured_on', $capturedDate)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $window = (int) ($rows->first()->window_days ?: 90);

        // Per-brand dead threshold: median units_sold over stocked items, of
        // which 10% (floored) is the "effectively not selling" line. A median
        // under 10 floors to 0 — the old zero-sales rule.
        $soldValues = [];
        foreach ($rows as $r) {
            if ((int) ($r->ending_units ?? 0) >= self::MIN_UNITS) {
                $soldValues[] = (float) ($r->units_sold ?? 0);
            }
        }
        $median        = $this->median($soldValues);
        $deadThreshold = $median !== null ? (int) floor(self::DEAD_PCT_OF_MEDIAN / 100 * $median) : 0;

        $items     = [];
        $deadUnits = 0;
        $deadCount = 0;
        foreach ($rows as $r) {
            $ending = (int) ($r->ending_units ?? 0);
            $sold   = (int) ($r->units_sold ?? 0);
            if ($ending < self::MIN_UNITS) {
                continue; // no stock on hand → not dead inventory
            }

            // Days of stock left at the window's sell rate. Null = nothing sold.
            // Dead = at/below the brand-relative threshold; > SLOW_COVER_DAYS =
            // overstocked / slow mover.
            $cover  = $sold > 0 ? ($ending * $window) / $sold : null;
            $status = $sold <= $deadThreshold
                ? 'dead'
                : (($cover !== null && $cover > self::SLOW_COVER_DAYS) ? 'slow' : 'healthy');

            if ($status === 'healthy') {
                continue; // only surface the problem stock
            }
            if ($status === 'dead') {
                $deadUnits += $ending;
                $deadCount++;
            }

            $items[] = [
                'key'         => $r->dimension_key,
                'label'       => $r->dimension_label ?: $r->dimension_key,
                'endingUnits' => $ending,
                'unitsSold'   => $sold,
                'sellThrough' => $r->sell_through_rate !== null ? round((float) $r->sell_through_rate, 4) : null,
                'coverDays'   => $cover !== null ? (int) round($cover) : null,
                'status'      => $status,
            ];
        }

        if ($items === []) {
            return null; // healthy catalog — nothing dead to report
        }

        // Most stock tied up first.
        usort($items, static fn (array $a, array $b): int => $b['endingUnits'] <=> $a['endingUnits']);

        return [
            'capturedOn'         => $capturedDate,
            'windowDays'         => $window,
            'rows'               => array_slice($items, 0, $limit),
            'deadCount'          => $deadCount,
            'deadUnits'          => $deadUnits,
            'flaggedItems'       => count($items),
            'deadThresholdUnits' => $deadThreshold,
            'medianUnits'        => $median,
        ];
    }

    /** @param array<int, float> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
