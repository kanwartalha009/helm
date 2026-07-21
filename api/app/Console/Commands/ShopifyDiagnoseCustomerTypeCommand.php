<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Shopify\ShopifyClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only probe: can ShopifyQL split revenue + units by NEW vs RETURNING
 * customer? This gates the Customer-Mix report (docs/feature-specs/
 * brand-inventory-and-customer-mix-reports.md §6.1) — Shopify documents
 * new/returning *counts* but NOT a dimension that splits net_sales/units_sold by
 * customer type, so we confirm empirically before committing to a schema, exactly
 * like `meta:diagnose-breakdown` did for the ASC audience key.
 *
 * Fires a handful of candidate ShopifyQL queries and prints each one's columns,
 * first rows, and parseErrors. The candidate that returns empty parseErrors AND
 * two rows (first-time / returning) carrying net_sales + units is the one to
 * build on. If NONE split revenue, we fall back to order-level classification.
 *
 * shopifyqlQuery reads protected customer data — the token needs read_reports +
 * read_customers (protected-data level 2). "Access denied" below = scope, not
 * syntax.
 *
 *   php artisan shopify:diagnose-customer-type flabelus
 *   php artisan shopify:diagnose-customer-type meller --days=60
 */
class ShopifyDiagnoseCustomerTypeCommand extends Command
{
    protected $signature = 'shopify:diagnose-customer-type {brand : brand slug or id} {--days=30 : window size in days}';
    protected $description = 'Probe ShopifyQL for a new-vs-returning customer split of revenue/units (gates the Customer-Mix report). Read-only.';

    public function handle(): int
    {
        $brand = $this->resolveBrand((string) $this->argument('brand'));
        if (! $brand) {
            return self::FAILURE;
        }

        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->first();

        $token = (string) ($conn->credentials['access_token'] ?? '');
        if (! $conn || $token === '') {
            $this->error("No usable Shopify connection for {$brand->name}.");

            return self::FAILURE;
        }

        $client = new ShopifyClient((string) $conn->external_id, $token);

        $days  = max(1, (int) $this->option('days'));
        $since = "-{$days}d";
        $ch    = "WHERE sales_channel = 'Online Store'";

        $this->info("Probing ShopifyQL customer-type split — {$brand->name}, last {$days} days");
        $this->line('shopifyqlQuery reads protected customer data: the token needs read_reports + read_customers');
        $this->line('(level-2 protected data). An "access denied" below is a SCOPE problem, not bad syntax.');
        $this->newLine();

        // ── Why this rewrite (Kanwar, 2026-07-21): the client sees Shopify's OWN
        // Analytics UI split total_sales by "New or returning customer" (New
        // €134,179.01 / Returning €49,847.02), so the data provably EXISTS. Our
        // first probe wrongly concluded it didn't — because every customer-type
        // query it fired also SHOW'd `units_sold`, an UNPROVEN metric name. If
        // `units_sold` is what Shopify rejected, the whole query fails with
        // parseErrors and we blame the DIMENSION by mistake.
        //
        // customersByMonthRange() proves net_sales + total_sales are valid `sales`
        // metrics. So every split probe below carries ONLY those two proven-good
        // metrics — any parseError now is unambiguously about the DIMENSION name,
        // never a metric. We sweep the realistic candidate identifiers; the winner
        // is the one that returns EMPTY parseErrors AND two rows carrying real
        // net_sales. Whatever name wins is exactly what we wire into RevenueFetcher.

        // A — sanity floor: the two metrics we KNOW are valid, no breakdown.
        // If even THIS parseErrors, the problem is scope/channel, not any dimension.
        $this->probe($client, 'A. Sanity — net_sales + total_sales, no breakdown (must pass)',
            "FROM sales SHOW net_sales, total_sales SINCE {$since} UNTIL today {$ch}");

        // B..H — candidate dimension names, each with ONLY proven-good metrics so
        // a failure can ONLY mean the dimension identifier is wrong.
        $candidates = [
            'B. customer_type'                 => 'customer_type',
            'C. first_time_vs_returning'       => 'first_time_vs_returning',
            'D. new_vs_returning'              => 'new_vs_returning',
            'E. new_or_returning_customer'     => 'new_or_returning_customer',
            'F. returning_customer'            => 'returning_customer',
            'G. customer_order_index_type'     => 'customer_order_index_type',
            'H. is_first_time_customer'        => 'is_first_time_customer',
        ];
        foreach ($candidates as $label => $dim) {
            $this->probe($client, "{$label} — GROUP BY {$dim}",
                "FROM sales SHOW net_sales, total_sales GROUP BY {$dim} SINCE {$since} UNTIL today {$ch}");
        }

        // I — winner sanity WITHOUT the channel filter, in case the split only
        // resolves store-wide (some dimensions don't compose with sales_channel).
        $this->probe($client, 'I. customer_type — no channel filter',
            "FROM sales SHOW net_sales, total_sales GROUP BY customer_type SINCE {$since} UNTIL today");

        // J — orders dataset, in case the split lives there instead of `sales`.
        $this->probe($client, 'J. FROM orders — GROUP BY customer_type',
            "FROM orders SHOW net_sales GROUP BY customer_type SINCE {$since} UNTIL today");

        // K — counts-only floor (known-good today; what we currently estimate from).
        $this->probe($client, 'K. FROM customers — new/returning counts (current estimate source)',
            "FROM customers SHOW new_customers, returning_customers SINCE {$since} UNTIL today");

        $this->newLine();
        $this->line('VERDICT: the winner is the FIRST candidate (B–J) with EMPTY parseErrors AND');
        $this->line('two rows each carrying a real net_sales value. Paste its query + rows back and');
        $this->line('that exact dimension name gets wired into RevenueFetcher to replace the estimate.');
        $this->line('If EVERY split (B–J) parseErrors but K passes, Shopify genuinely exposes only');
        $this->line('counts on this plan → the estimate stays and we document why.');

        return self::SUCCESS;
    }

    private function probe(ShopifyClient $client, string $label, string $query): void
    {
        $this->line("── {$label}");
        $this->line("   {$query}");

        try {
            $sqlGql = <<<'GQL'
query ($q: String!) {
  shopifyqlQuery(query: $q) {
    tableData { columns { name } rows }
    parseErrors
  }
}
GQL;
            $res  = $client->graphql($sqlGql, ['q' => $query], '2026-04');
            $resp = $res['shopifyqlQuery'] ?? null;

            if (! $resp) {
                $this->warn('   → no response');
            } elseif (! empty($resp['parseErrors'])) {
                $pe = $resp['parseErrors'];
                $this->warn('   → parseErrors: ' . (is_array($pe) ? json_encode($pe) : (string) $pe));
            } else {
                $cols = array_map(static fn ($c) => $c['name'] ?? '?', $resp['tableData']['columns'] ?? []);
                $rows = $resp['tableData']['rows'] ?? [];
                $this->info('   → OK · columns: ' . implode(', ', $cols));
                if ($rows === []) {
                    $this->line('     (valid query, but no rows in this window)');
                }
                foreach (array_slice($rows, 0, 6) as $r) {
                    $this->line('     ' . json_encode($r));
                }
            }
        } catch (Throwable $e) {
            $this->warn('   → error: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function resolveBrand(string $arg): ?Brand
    {
        $lower = strtolower(trim($arg));

        $brand = is_numeric($arg)
            ? Brand::find((int) $arg)
            : (Brand::query()
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()
                    ->where('name', 'like', '%' . $arg . '%')
                    ->orWhere('slug', 'like', '%' . $arg . '%')
                    ->first());

        if (! $brand) {
            $this->error("Brand not found: {$arg}");
            $hints = Brand::query()
                ->where('name', 'like', '%' . $arg . '%')
                ->orWhere('slug', 'like', '%' . $arg . '%')
                ->limit(10)
                ->get(['name', 'slug']);
            foreach ($hints as $h) {
                $this->line("  - {$h->name}  (slug: {$h->slug})");
            }
        }

        return $brand;
    }
}
