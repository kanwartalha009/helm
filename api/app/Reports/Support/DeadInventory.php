<?php

declare(strict_types=1);

namespace App\Reports\Support;

use App\Models\InventorySnapshot;

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

        $rows = InventorySnapshot::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', $dimensionType)
            ->whereDate('captured_on', $capturedOn)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $window = (int) ($rows->first()->window_days ?: 90);

        $items     = [];
        $deadUnits = 0;
        $deadCount = 0;
        foreach ($rows as $r) {
            $ending = (int) ($r->ending_units ?? 0);
            $sold   = (int) ($r->units_sold ?? 0);
            if ($ending < self::MIN_UNITS) {
                continue; // no stock on hand → not dead inventory
            }

            // Days of stock left at the window's sell rate. Null = nothing sold
            // (truly dead). > SLOW_COVER_DAYS = overstocked / slow mover.
            $cover  = $sold > 0 ? ($ending * $window) / $sold : null;
            $status = $sold === 0
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
            'capturedOn'   => (string) $capturedOn,
            'windowDays'   => $window,
            'rows'         => array_slice($items, 0, $limit),
            'deadCount'    => $deadCount,
            'deadUnits'    => $deadUnits,
            'flaggedItems' => count($items),
        ];
    }
}
