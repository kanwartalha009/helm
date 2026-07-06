<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Platforms\Shopify\RevenueFetcher;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill granular Shopify commerce — sales broken down BY COUNTRY, BY PRODUCT
 * and BY CATEGORY (product_type) — into commerce_daily_metrics for the
 * reporting engine's Country and Product reports (feature spec slice 2.1).
 *
 * One ShopifyQL call per dimension per brand over the whole range; additive
 * upsert keyed on (brand, date, dimension_type, dimension_key) that NEVER
 * touches daily_metrics. Missing data stays missing (a wrong dimension name
 * surfaces as a logged parseError + empty result, never a fake zero).
 *
 *   php artisan shopify:backfill-commerce                 # all active brands, since 2025-01-01
 *   php artisan shopify:backfill-commerce meller          # one brand
 *   php artisan shopify:backfill-commerce --since=2025-01-01
 *   php artisan shopify:backfill-commerce --dimension=country   # one dimension only
 *
 * Dimension names below are verified against Shopify's ShopifyQL reference
 * (shopify.dev/docs/api/shopifyql): geography on `FROM sales` is billing_country
 * / billing_region; category is product_type; product is product_title. If a
 * brand ever logs `shopify.shopifyql.parse_error` for a dimension, it's a
 * one-line fix in self::DIMENSIONS.
 */
class ShopifyBackfillCommerceCommand extends Command
{
    protected $signature = 'shopify:backfill-commerce '
        . '{brand? : slug or id; omit for all active brands} '
        . '{--since=2025-01-01 : first day to pull (Y-m-d)} '
        . '{--dimension= : limit to one of country|product|category} '
        . '{--missing : only brands/dimensions with NO existing rows (freshly added brands) — skips already-synced ones}';

    protected $description = 'Backfill Shopify sales by country / product / category into commerce_daily_metrics for the reporting engine.';

    /**
     * dimension_type => ShopifyQL `sales` dimension name (the GROUP BY field).
     * Adjust a value here if the backfill logs a parseError for that dimension.
     *
     * @var array<string, string>
     */
    private const DIMENSIONS = [
        'country'  => 'billing_country',   // verified: ShopifyQL geography dimension on FROM sales
        'product'  => 'product_title',
        'category' => 'product_type',
    ];

    public function handle(RevenueFetcher $fetcher, FxService $fx): int
    {
        $since = (string) $this->option('since');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since must be a Y-m-d date.');

            return self::FAILURE;
        }

        $dimensions = self::DIMENSIONS;
        $only = $this->option('dimension');
        if ($only !== null && $only !== '') {
            $only = strtolower((string) $only);
            if (! isset(self::DIMENSIONS[$only])) {
                $this->error('--dimension must be one of: ' . implode(', ', array_keys(self::DIMENSIONS)) . '.');

                return self::FAILURE;
            }
            $dimensions = [$only => self::DIMENSIONS[$only]];
        }

        $brands = $this->resolveBrands();
        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $missing   = (bool) $this->option('missing');
        $totalRows = 0;

        foreach ($brands as $brand) {
            $conn = $brand->connections->firstWhere('platform', 'shopify');
            if (! $conn || $conn->status !== 'active') {
                $this->line("· {$brand->name}: no active Shopify connection — skipped.");
                continue;
            }

            $until    = CarbonImmutable::now($brand->timezone ?: 'UTC')->toDateString();
            $currency = $brand->base_currency;
            $fxCache  = [];   // date string => ?float, dedupes FX lookups within the brand
            $months   = $this->monthWindows($since, $until);

            foreach ($dimensions as $type => $dim) {
                if ($missing && CommerceDailyMetric::query()
                    ->where('brand_id', $brand->id)
                    ->where('dimension_type', $type)
                    ->exists()
                ) {
                    $this->line("· {$brand->name} [{$type}]: already has data — skipped (--missing).");
                    continue;
                }

                $dimRows = 0;
                $failed  = 0;

                // Month-by-month: a single full-range call groups by day ×
                // dimension and would blow past ShopifyQL's 1000-row default,
                // silently dropping the most recent days (ORDER BY day keeps the
                // head). Monthly windows stay far under the cap and keep each
                // query's complexity low for the rate limiter.
                foreach ($months as [$chunkStart, $chunkEnd]) {
                    try {
                        $sales = $fetcher->salesByDimensionRange($conn, $dim, $chunkStart, $chunkEnd);
                    } catch (Throwable $e) {
                        $this->error("· {$brand->name} [{$type}] {$chunkStart}: {$e->getMessage()}");
                        $failed++;
                        continue;
                    }

                    if ($sales === []) {
                        continue;
                    }

                    $records = $this->records($brand, $type, $sales, $currency, $fxCache, $fx);

                    // Insert new (brand, date, dimension) rows; refresh metrics on
                    // existing ones. The update list is metrics-only, so a re-run
                    // never invents zeros or clobbers another dimension's rows.
                    foreach (array_chunk($records, 500) as $chunk) {
                        CommerceDailyMetric::upsert(
                            $chunk,
                            ['brand_id', 'date', 'dimension_type', 'dimension_key'],
                            ['dimension_label', 'orders', 'units', 'net_sales', 'total_sales', 'refunds_amount', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                        );
                    }

                    $dimRows += count($records);
                    usleep(150_000); // breather between ShopifyQL calls
                }

                if ($dimRows === 0 && $failed === 0) {
                    $this->line("· {$brand->name} [{$type}]: no rows for {$since}..{$until} (empty or dimension '{$dim}' not recognised — check logs).");
                } else {
                    $note = $failed > 0 ? " — {$failed} month(s) errored, re-run to fill (upsert is idempotent)" : '';
                    $this->info("· {$brand->name} [{$type}]: {$dimRows} rows backfilled ({$since}..{$until}){$note}.");
                }

                $totalRows += $dimRows;
            }
        }

        $this->info("Done. {$totalRows} commerce rows upserted across {$brands->count()} brand(s).");

        return self::SUCCESS;
    }

    /**
     * Map fetched ShopifyQL rows to commerce_daily_metrics records, resolving
     * each day's USD rate once (cached per brand). Revenue stays native; the
     * stored fx snapshot lets reports show USD without converting at read time.
     *
     * @param array<int, array<string, mixed>> $sales
     * @param array<string, ?float>            $fxCache  by-date cache, mutated
     * @return array<int, array<string, mixed>>
     */
    private function records(Brand $brand, string $type, array $sales, string $currency, array &$fxCache, FxService $fx): array
    {
        $records = [];
        foreach ($sales as $r) {
            $date = (string) $r['date'];
            $key  = trim((string) $r['key']);
            if ($key === '') {
                continue;
            }

            $fxRate = $fxCache[$date]
                ??= $fx->cachedToUsd($currency, CarbonImmutable::parse($date));

            $records[] = [
                'brand_id'        => $brand->id,
                'date'            => $date,
                'dimension_type'  => $type,
                'dimension_key'   => mb_substr($key, 0, 191),
                'dimension_label' => mb_substr((string) $r['label'], 0, 191),
                'orders'          => $r['orders'] ?? null,
                'units'           => $r['units'] ?? null,
                'net_sales'       => $r['net'] ?? null,
                'total_sales'     => $r['total'] ?? null,
                'refunds_amount'  => $r['refunds'] ?? null,
                'currency'        => $currency,
                'fx_rate_to_usd'  => $fxRate,
                'is_complete'     => true,
                'pulled_at'       => now(),
            ];
        }

        return $records;
    }

    /**
     * Month-aligned [start, end] windows spanning [since, until], so each
     * ShopifyQL call covers at most one calendar month.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function monthWindows(string $since, string $until): array
    {
        $cursor = CarbonImmutable::parse($since)->startOfDay();
        $end    = CarbonImmutable::parse($until)->startOfDay();

        $out = [];
        while ($cursor <= $end) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();
            $chunkEnd = $monthEnd > $end ? $end : $monthEnd;
            $out[]    = [$cursor->toDateString(), $chunkEnd->toDateString()];
            $cursor   = $chunkEnd->addDay();
        }

        return $out;
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
