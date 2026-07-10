<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Aggregation\DashboardQuery;
use App\Services\Aggregation\DashboardQuerySetBased;
use Illuminate\Console\Command;

/**
 * Runs BOTH dashboard engines against the live database with the same params
 * and diffs every cell. This is the gate for flipping
 * HELM_DASHBOARD_ENGINE=set (config/helm.php): flip only after every
 * combination you care about reports "PARITY OK".
 *
 * Recommended production run (covers the filter bar's whole surface):
 *
 *   php artisan helm:dashboard-parity
 *   php artisan helm:dashboard-parity --currency=USD
 *   php artisan helm:dashboard-parity --window=30 --compare=yesterday,last7,last30,lastmonth,mtd
 *   php artisan helm:dashboard-parity --currency=USD --window=90 --compare=last30 --metric=net
 *
 * Floats compare with a 0.01 tolerance (cent-level). Keys that exist only in
 * the set-based engine (additive fxPending / fxPendingDays flags) are excluded
 * from the diff by design.
 */
class DashboardParityCommand extends Command
{
    protected $signature = 'helm:dashboard-parity
        {--currency= : Display currency (e.g. USD); omit for native}
        {--window=7 : Rolling window days (7/30/90)}
        {--compare= : Comma list of YoY periods (yesterday,last7,last30,lastmonth,mtd)}
        {--metric=total : Comparison metric (net|total)}
        {--manager=all : Manager filter (all|unassigned|<user id>)}
        {--max-diffs=50 : Cap on printed diff lines}';

    protected $description = 'Diff the legacy and set-based dashboard engines cell-by-cell on live data';

    /** Keys that only the set-based engine emits — additive, excluded from parity. */
    private const ADDITIVE_KEYS = ['fxPending', 'fxPendingDays'];

    private const FLOAT_TOLERANCE = 0.011;

    public function handle(DashboardQuery $legacy, DashboardQuerySetBased $setBased): int
    {
        $params = array_filter([
            'currency' => $this->option('currency'),
            'window'   => $this->option('window'),
            'compare'  => $this->option('compare'),
            'metric'   => $this->option('metric'),
            'manager'  => $this->option('manager'),
        ], static fn ($v) => $v !== null && $v !== '');

        $this->info('Params: ' . json_encode($params));

        $t0         = hrtime(true);
        $legacyRows = $legacy->run($params);
        $t1         = hrtime(true);
        $setRows    = $setBased->run($params);
        $t2         = hrtime(true);

        $legacyMs = ($t1 - $t0) / 1e6;
        $setMs    = ($t2 - $t1) / 1e6;

        $this->line(sprintf('legacy engine: %d rows in %.0f ms', count($legacyRows), $legacyMs));
        $this->line(sprintf('set engine:    %d rows in %.0f ms', count($setRows), $setMs));

        // Key rows by brand id — the sort is asserted separately below.
        $byIdLegacy = [];
        foreach ($legacyRows as $r) {
            $byIdLegacy[$r['brand']['id']] = $r;
        }
        $byIdSet = [];
        foreach ($setRows as $r) {
            $byIdSet[$r['brand']['id']] = $r;
        }

        $diffs = [];

        foreach (array_diff(array_keys($byIdLegacy), array_keys($byIdSet)) as $missing) {
            $diffs[] = ['brand#' . $missing, '(row present)', '(row MISSING in set engine)'];
        }
        foreach (array_diff(array_keys($byIdSet), array_keys($byIdLegacy)) as $extra) {
            $diffs[] = ['brand#' . $extra, '(row MISSING in legacy engine)', '(row present)'];
        }

        foreach ($byIdLegacy as $id => $legacyRow) {
            if (! isset($byIdSet[$id])) {
                continue;
            }
            $slug = $legacyRow['brand']['slug'] ?? ('brand#' . $id);
            $this->diffValue($diffs, $slug, $legacyRow, $byIdSet[$id]);
        }

        // Row ORDER must match too — the sort is part of the contract.
        $legacyOrder = array_column(array_column($legacyRows, 'brand'), 'id');
        $setOrder    = array_column(array_column($setRows, 'brand'), 'id');
        if ($legacyOrder !== $setOrder) {
            $diffs[] = ['(row order)', implode(',', $legacyOrder), implode(',', $setOrder)];
        }

        if ($diffs === []) {
            $this->info(sprintf(
                'PARITY OK — %d rows identical (floats within %.2f). Set engine %.1fx the legacy wall time.',
                count($legacyRows),
                self::FLOAT_TOLERANCE,
                $legacyMs > 0 ? $setMs / max($legacyMs, 0.001) : 0,
            ));

            return self::SUCCESS;
        }

        $max = (int) $this->option('max-diffs');
        $this->error(sprintf('PARITY FAILED — %d differing cell(s):', count($diffs)));
        $this->table(['path', 'legacy', 'set'], array_slice($diffs, 0, $max));
        if (count($diffs) > $max) {
            $this->warn(sprintf('… and %d more (raise --max-diffs to see them).', count($diffs) - $max));
        }

        return self::FAILURE;
    }

    /**
     * Recursive structural diff. $path accumulates "slug.yesterday.revenue".
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $diffs
     */
    private function diffValue(array &$diffs, string $path, mixed $legacy, mixed $set): void
    {
        if (is_array($legacy) && is_array($set)) {
            $keys = array_unique(array_merge(array_keys($legacy), array_keys($set)));
            foreach ($keys as $key) {
                if (in_array($key, self::ADDITIVE_KEYS, true) && ! array_key_exists($key, $legacy)) {
                    continue; // additive set-engine field, excluded by design
                }
                if (! array_key_exists($key, $legacy)) {
                    $diffs[] = ["{$path}.{$key}", '(absent)', $this->fmt($set[$key])];
                    continue;
                }
                if (! array_key_exists($key, $set)) {
                    $diffs[] = ["{$path}.{$key}", $this->fmt($legacy[$key]), '(absent)'];
                    continue;
                }
                $this->diffValue($diffs, "{$path}.{$key}", $legacy[$key], $set[$key]);
            }

            return;
        }

        if ($legacy === null || $set === null) {
            if ($legacy !== $set) {
                $diffs[] = [$path, $this->fmt($legacy), $this->fmt($set)];
            }

            return;
        }

        $bothNumeric = (is_int($legacy) || is_float($legacy)) && (is_int($set) || is_float($set));
        if ($bothNumeric) {
            if (abs((float) $legacy - (float) $set) > self::FLOAT_TOLERANCE) {
                $diffs[] = [$path, $this->fmt($legacy), $this->fmt($set)];
            }

            return;
        }

        if ($legacy !== $set) {
            $diffs[] = [$path, $this->fmt($legacy), $this->fmt($set)];
        }
    }

    private function fmt(mixed $v): string
    {
        return match (true) {
            $v === null      => 'null',
            is_bool($v)      => $v ? 'true' : 'false',
            is_scalar($v)    => (string) $v,
            default          => json_encode($v) ?: '(unencodable)',
        };
    }
}
