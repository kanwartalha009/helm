<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\ProductCatalog;
use App\Platforms\Shopify\CatalogFetcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refresh the Shopify product catalog (stock + variants + handle↔title) into
 * product_catalog for the Inventory Intelligence report. A snapshot, so it just
 * upserts the current state per (brand, handle). Additive; safe to re-run.
 *
 *   php artisan shopify:sync-catalog
 *   php artisan shopify:sync-catalog ganzitos
 */
class ShopifySyncCatalogCommand extends Command
{
    protected $signature = 'shopify:sync-catalog {brand? : slug or id; omit for all active brands}';
    protected $description = 'Snapshot each brand\'s Shopify product catalog (stock, variants, handle) into product_catalog.';

    public function handle(CatalogFetcher $fetcher): int
    {
        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'shopify');
            if (! $conn || $conn->status !== 'active') {
                $this->line("· {$brand->name}: no active Shopify connection — skipped.");
                continue;
            }

            try {
                $products = $fetcher->fetchCatalog($conn);
            } catch (Throwable $e) {
                $this->error("· {$brand->name}: {$e->getMessage()}");
                continue;
            }

            if ($products === []) {
                $this->line("· {$brand->name}: no products returned.");
                continue;
            }

            $now     = now();
            $records = [];
            foreach ($products as $p) {
                $records[] = [
                    'brand_id'        => $brand->id,
                    'product_id'      => mb_substr((string) $p['product_id'], 0, 64),
                    'handle'          => mb_substr((string) $p['handle'], 0, 191),
                    'title'           => mb_substr((string) $p['title'], 0, 255),
                    'product_type'    => $p['product_type'] !== null ? mb_substr((string) $p['product_type'], 0, 191) : null,
                    'status'          => $p['status'],
                    // upsert bypasses Eloquent casts, so encode the json columns by hand.
                    'tags'            => json_encode($p['tags'] ?? []),
                    'variant_count'   => (int) $p['variant_count'],
                    'total_inventory' => (int) $p['total_inventory'],
                    'variants'        => json_encode($p['variants'] ?? []),
                    // GO-1.2: Shopify unitCost (nullable — a product with no cost set,
                    // or a shop that withholds the permission, stays NULL, never 0).
                    'unit_cost'          => $p['unit_cost'] ?? null,
                    'unit_cost_currency' => $p['unit_cost_currency'] ?? null,
                    'captured_at'     => $now,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            foreach (array_chunk($records, 500) as $chunk) {
                ProductCatalog::upsert(
                    $chunk,
                    ['brand_id', 'handle'],
                    ['product_id', 'title', 'product_type', 'status', 'tags', 'variant_count', 'total_inventory', 'variants', 'unit_cost', 'unit_cost_currency', 'captured_at', 'updated_at'],
                );
            }

            $this->info("· {$brand->name}: " . count($products) . ' products snapshotted.');
            $total += count($records);
        }

        $this->info("Done. {$total} product rows upserted across {$brands->count()} brand(s).");

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
