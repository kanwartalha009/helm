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

        // A — sanity: confirm units_sold + revenue return at all (no breakdown).
        $this->probe($client, 'A. Sanity — units_sold + revenue, no breakdown',
            "FROM sales SHOW units_sold, net_sales, total_sales SINCE {$since} UNTIL today {$ch}");

        // B — the target: split every metric by customer_type in one query.
        $this->probe($client, 'B. TARGET — GROUP BY customer_type',
            "FROM sales SHOW customer_type, net_sales, total_sales, units_sold, orders GROUP BY customer_type SINCE {$since} UNTIL today {$ch}");

        // C–D — likely alternate dimension names for the same split.
        $this->probe($client, 'C. Alternate — GROUP BY returning_customer',
            "FROM sales SHOW returning_customer, net_sales, units_sold, orders GROUP BY returning_customer SINCE {$since} UNTIL today {$ch}");

        $this->probe($client, 'D. Alternate — GROUP BY customer_type WITHOUT channel filter',
            "FROM sales SHOW customer_type, net_sales, units_sold GROUP BY customer_type SINCE {$since} UNTIL today");

        // E — metric-only (no split): confirms which returning/new fields `sales` exposes.
        $this->probe($client, 'E. Metrics on sales — returning_customers / returning_customer_rate',
            "FROM sales SHOW net_sales, orders, customers, returning_customers, returning_customer_rate SINCE {$since} UNTIL today {$ch}");

        // F — orders dataset, in case the split lives there.
        $this->probe($client, 'F. FROM orders — GROUP BY customer_type',
            "FROM orders SHOW customer_type, net_sales, ordered_item_quantity, orders GROUP BY customer_type SINCE {$since} UNTIL today");

        // G — customers dataset new/returning counts (known-good; the floor if B–F fail).
        $this->probe($client, 'G. FROM customers — new/returning counts (counts only)',
            "FROM customers SHOW new_customers, returning_customers SINCE {$since} UNTIL today");

        $this->newLine();
        $this->line('Verdict: the winner is the candidate with EMPTY parseErrors AND two rows');
        $this->line('(first-time / returning) each carrying net_sales + units. If only G works,');
        $this->line('Shopify won\'t split revenue by customer type → build the order-level fallback.');

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
