<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Platforms\Shopify\RevenueFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill daily net_sales + total_sales from Shopify's analytics engine
 * (ShopifyQL) for a date range — powers the year-over-year comparison
 * (Bosco, 2026-06-19). One fast ShopifyQL call per brand covering the whole
 * range; sales-only upsert that NEVER touches orders / refunds / spend on
 * existing rows.
 *
 *   php artisan shopify:backfill-sales                 # all active brands, since 2025-01-01
 *   php artisan shopify:backfill-sales meller          # one brand
 *   php artisan shopify:backfill-sales --since=2025-01-01
 */
class ShopifyBackfillSalesCommand extends Command
{
    protected $signature = 'shopify:backfill-sales {brand? : slug or id; omit for all active brands} {--since=2025-01-01 : first day to pull (Y-m-d)}';

    protected $description = 'Backfill daily net_sales + total_sales from Shopify analytics for the year-over-year comparison (sales-only upsert).';

    public function handle(RevenueFetcher $fetcher): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $totalDays = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'shopify');
            if (! $conn || $conn->status !== 'active') {
                $this->line("· {$brand->name}: no active Shopify connection — skipped.");
                continue;
            }

            $until = CarbonImmutable::now($brand->timezone ?: 'UTC')->toDateString();

            try {
                $map = $fetcher->salesByDayRange($conn, $since, $until);
            } catch (Throwable $e) {
                $this->error("· {$brand->name}: {$e->getMessage()}");
                continue;
            }

            if ($map === []) {
                $this->line("· {$brand->name}: no sales returned for {$since}..{$until} (store may not have existed).");
                continue;
            }

            $rows = [];
            foreach ($map as $day => $figures) {
                $rows[] = [
                    'brand_id'    => $brand->id,
                    'platform'    => 'shopify',
                    'date'        => $day,
                    'net_sales'   => $figures['net'] ?? null,
                    'total_sales' => $figures['total'] ?? null,
                    'currency'    => $brand->base_currency,
                    'is_complete' => true,
                    'pulled_at'   => now(),
                ];
            }

            // Insert missing historical days; update net/total on existing rows.
            // The update list excludes order-based columns, so a backfill can
            // never zero out revenue / refunds / orders / spend already synced.
            foreach (array_chunk($rows, 500) as $chunk) {
                DailyMetric::upsert(
                    $chunk,
                    ['brand_id', 'platform', 'date'],
                    ['net_sales', 'total_sales', 'pulled_at'],
                );
            }

            $totalDays += count($rows);
            $this->info("· {$brand->name}: " . count($rows) . " day-rows backfilled ({$since}..{$until}).");

            usleep(200_000); // 0.2s breather between brands to be gentle on the API
        }

        $this->info("Done. {$totalDays} day-rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Brand> */
    private function resolveBrands(): \Illuminate\Support\Collection
    {
        $arg = $this->argument('brand');

        if ($arg === null) {
            return Brand::query()->with('connections')->where('status', 'active')->orderBy('name')->get();
        }

        // Match on id, exact slug/name (case-insensitive), or a partial name/slug
        // — so "meller" finds the brand "Meller" without the generated slug.
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
