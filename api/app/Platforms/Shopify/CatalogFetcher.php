<?php

declare(strict_types=1);

namespace App\Platforms\Shopify;

use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls a brand's current Shopify product catalog — handle, title, tags, stock,
 * and variants — for the Inventory Intelligence report. Handle is lower-cased so
 * it matches the handles parsed from Meta ad landing URLs (AdProductFetcher), so
 * the report can join ad spend → product → commerce revenue.
 *
 * Only active products; obvious non-products (gift cards, shoe boxes, samples,
 * test items) are skipped by title.
 */
final class CatalogFetcher
{
    private const QUERY = <<<'GQL'
    query ($cursor: String) {
      products(first: 50, after: $cursor, query: "status:active") {
        nodes {
          id
          handle
          title
          productType
          status
          tags
          totalInventory
          variants(first: 100) {
            nodes {
              id
              title
              inventoryQuantity
              inventoryItem { unitCost { amount currencyCode } }
            }
          }
        }
        pageInfo { hasNextPage endCursor }
      }
    }
    GQL;

    /**
     * @return array<int, array<string, mixed>> one row per product
     */
    public function fetchCatalog(PlatformConnection $conn): array
    {
        $token = (string) ($conn->credentials['access_token'] ?? '');
        if ($token === '' || ! $conn->external_id) {
            return [];
        }
        $client = new ShopifyClient((string) $conn->external_id, $token);

        $out    = [];
        $cursor = null;
        $pages  = 0;

        do {
            try {
                $data = $client->graphql(self::QUERY, ['cursor' => $cursor]);
            } catch (Throwable $e) {
                Log::warning('shopify.catalog.request_failed', ['brand_id' => $conn->brand_id, 'error' => $e->getMessage()]);
                break;
            }

            $products = $data['products'] ?? null;
            if (! is_array($products)) {
                break;
            }

            foreach (($products['nodes'] ?? []) as $p) {
                $handle = strtolower(trim((string) ($p['handle'] ?? '')));
                $title  = (string) ($p['title'] ?? '');
                if ($handle === '' || $this->isNonProduct($title)) {
                    continue;
                }

                $variants  = [];
                $costs     = [];   // non-null variant unit costs only
                $costCcy   = null;
                foreach (($p['variants']['nodes'] ?? []) as $v) {
                    $variants[] = [
                        't' => (string) ($v['title'] ?? ''),
                        'q' => (int) ($v['inventoryQuantity'] ?? 0),
                    ];

                    // unitCost is NULLABLE (Shopify: unset cost, or the "View product
                    // costs" permission not granted). Absent → skip; never coerce to 0.
                    $raw = $v['inventoryItem']['unitCost']['amount'] ?? null;
                    if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                        $costs[] = (float) $raw;
                        $costCcy ??= (string) ($v['inventoryItem']['unitCost']['currencyCode'] ?? '') ?: null;
                    }
                }

                // Product-level cost = mean of the variants that HAVE a cost (size
                // variants nearly always share one). Products where no variant exposes
                // a cost stay null → the resolver falls back to a manual cost, then the
                // brand margin, then "—". Documented in the migration + surfaced as the
                // cost `source` so nobody mistakes an inferred number for a real one.
                $unitCost = $costs !== [] ? round(array_sum($costs) / count($costs), 2) : null;

                $out[] = [
                    'product_id'      => (string) ($p['id'] ?? ''),
                    'handle'          => $handle,
                    'title'           => $title,
                    'product_type'    => (string) ($p['productType'] ?? '') ?: null,
                    'status'          => strtolower((string) ($p['status'] ?? '')) ?: null,
                    'tags'            => is_array($p['tags'] ?? null) ? $p['tags'] : [],
                    'variant_count'   => count($variants),
                    'total_inventory' => (int) ($p['totalInventory'] ?? 0),
                    'variants'        => $variants,
                    'unit_cost'          => $unitCost,
                    'unit_cost_currency' => $unitCost !== null ? $costCcy : null,
                ];
            }

            $pi      = $products['pageInfo'] ?? [];
            $cursor  = (string) ($pi['endCursor'] ?? '');
            $hasNext = (bool) ($pi['hasNextPage'] ?? false);
            $pages++;
        } while ($hasNext && $cursor !== '' && $pages < 300);

        return $out;
    }

    /** Skip obvious non-products so they don't pollute the report (mirrors the Ganzitos rule). */
    private function isNonProduct(string $title): bool
    {
        return (bool) preg_match(
            '/(prueba|papel\s*regalo|caja\s*(de\s*)?zapatos|tarjeta\s*regalo|gift\s*card|muestra|sample|\btest\b)/i',
            $title
        );
    }
}
