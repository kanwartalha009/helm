<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\InventorySnapshot;
use App\Platforms\Shopify\RevenueFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Capture today's inventory snapshot per product + collection for the reporting
 * engine's dead-stock analysis: stock on hand, units sold over a trailing
 * window, and sell-through (ShopifyQL `FROM inventory`). One snapshot row per
 * (brand, today, dimension, key); the dead-inventory report reads the latest.
 *
 * Run daily (schedule it) so dead stock stays current. Additive — only writes
 * inventory_snapshots, never daily_metrics or commerce_daily_metrics.
 *
 *   php artisan shopify:sync-inventory                 # all active brands, 90-day window
 *   php artisan shopify:sync-inventory meller          # one brand
 *   php artisan shopify:sync-inventory --window=60     # 60-day sell-through window
 */
class ShopifySyncInventoryCommand extends Command
{
    protected $signature = 'shopify:sync-inventory '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--window=90 : trailing days for units-sold / sell-through}';

    protected $description = 'Capture inventory stock + sell-through by product and collection for the dead-stock report.';

    /** dimension_type => ShopifyQL inventory dimension (verified vs the inventory schema). */
    private const DIMENSIONS = [
        'product'    => 'product_title',
        'collection' => 'product_type',
    ];

    public function handle(RevenueFetcher $fetcher): int
    {
        $window = max(1, (int) $this->option('window'));

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $totalRows = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'shopify');
            if (! $conn || $conn->status !== 'active') {
                $this->line("· {$brand->name}: no active Shopify connection — skipped.");
                continue;
            }

            $tz         = $brand->timezone ?: 'UTC';
            $capturedOn = CarbonImmutable::now($tz)->toDateString();
            $since      = CarbonImmutable::now($tz)->subDays($window)->toDateString();

            foreach (self::DIMENSIONS as $type => $dim) {
                try {
                    $rows = $fetcher->inventoryByDimension($conn, $dim, $since, $capturedOn);
                } catch (Throwable $e) {
                    $this->error("· {$brand->name} [{$type}]: {$e->getMessage()}");
                    continue;
                }

                if ($rows === []) {
                    $this->line("· {$brand->name} [{$type}]: no inventory rows (empty, or dimension '{$dim}' unsupported — check logs).");
                    continue;
                }

                $records = [];
                foreach ($rows as $r) {
                    $key = trim((string) $r['key']);
                    if ($key === '') {
                        continue;
                    }
                    $records[] = [
                        'brand_id'          => $brand->id,
                        'captured_on'       => $capturedOn,
                        'dimension_type'    => $type,
                        'dimension_key'     => mb_substr($key, 0, 191),
                        'dimension_label'   => mb_substr((string) $r['label'], 0, 191),
                        'ending_units'      => $r['ending_units'] ?? null,
                        'units_sold'        => $r['units_sold'] ?? null,
                        'sell_through_rate' => $r['sell_through_rate'] ?? null,
                        'window_days'       => $window,
                        'pulled_at'         => now(),
                    ];
                }

                foreach (array_chunk($records, 500) as $chunk) {
                    InventorySnapshot::upsert(
                        $chunk,
                        ['brand_id', 'captured_on', 'dimension_type', 'dimension_key'],
                        ['dimension_label', 'ending_units', 'units_sold', 'sell_through_rate', 'window_days', 'pulled_at'],
                    );
                }

                $totalRows += count($records);
                $this->info("· {$brand->name} [{$type}]: " . count($records) . " inventory rows captured ({$capturedOn}, {$window}d window).");

                usleep(200_000);
            }
        }

        $this->info("Done. {$totalRows} inventory rows captured across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Brand> */
    private function resolveBrands(): \Illuminate\Support\Collection
    {
        $arg = $this->argument('brand');

        if ($arg === null) {
            return Brand::query()->with('connections')->where('status', 'active')->orderBy('name')->get();
        }

        $argStr = (string) $arg;
        $lower  = strtolower(trim($argStr));

        $brand = is_numeric($argStr)
            ? Brand::query()->with('connections')->find((int) $argStr)
            : (Brand::query()->with('connections')
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()->with('connections')
                    ->where('name', 'like', '%' . $argStr . '%')
                    ->orWhere('slug', 'like', '%' . $argStr . '%')
                    ->first());

        return collect($brand ? [$brand] : []);
    }
}
