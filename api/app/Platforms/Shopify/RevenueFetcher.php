<?php

declare(strict_types=1);

namespace App\Platforms\Shopify;

use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pulls Shopify order metrics and assembles MetricSnapshots. Delegates HTTP to
 * ShopifyClient.
 *
 * Field definitions:
 *   - revenue        = sum of order.totalPriceSet.shopMoney.amount
 *                      (gross order value at creation — tax + shipping +
 *                      discounts already applied, refunds NOT subtracted).
 *                      Drives the dashboard "Total revenue" (gross) toggle.
 *   - net_sales      = Shopify's OWN "Net sales" figure, pulled from the
 *                      analytics engine via ShopifyQL (`shopifyqlQuery`): product
 *                      revenue after discounts and returns, excluding tax,
 *                      shipping, and duties, with returns attributed by refund
 *                      date. This is the exact number behind Analytics > Reports.
 *                      We pull it directly rather than reconstructing from order
 *                      money fields — reconstruction was neither exact (Shopify's
 *                      tax/return rounding doesn't map to order fields) nor
 *                      stable (the "current" fields mutate as refunds process).
 *                      Default dashboard metric.
 *   - refunds_amount = sum of order.totalRefundedSet.shopMoney.amount
 *                      (order-date aggregate; legacy "total refunds" column)
 *   - revenue_net    = revenue - refunds_amount (legacy; order-date)
 *   - orders         = count of orders created in the window
 *   - refunded_orders = count of orders with totalRefundedSet > 0
 *
 * IMPORTANT: do NOT use currentTotalPriceSet for `revenue`. That field reflects
 * the order's current state — refunds are already excluded — so subtracting
 * refunds again gives you net-of-refunds-twice.
 *
 * net_sales failures degrade gracefully: if ShopifyQL errors (scope/syntax/
 * transient), net_sales is left null (missing, NOT zero — spec rule 9) and the
 * order-based revenue/orders/refunds still record. The failure is logged.
 */
final class RevenueFetcher
{
    /** Phase-1 single-page limit per the spec. We log + flag if a day exceeds it. */
    private const PAGE_SIZE = 100;

    /** Bigger page size for the historical paginated scan — Shopify caps at 250. */
    private const HISTORY_PAGE_SIZE = 250;

    /** Hard safety cap on cursor pages — 200 × 250 = 50,000 orders/sync. */
    private const MAX_HISTORY_PAGES = 200;

    /**
     * Admin API version that exposes `shopifyqlQuery`. The field was removed in
     * 2024-07 and reinstated since; the client's default version (see
     * ShopifyClient) predates the reinstatement, so the ShopifyQL calls pass
     * this version explicitly while the order queries keep the default.
     */
    private const SHOPIFYQL_API_VERSION = '2026-04';

    public function __construct(private readonly OAuthService $oauth) {}

    /**
     * Order source_names that count as the Online Store channel (lowercased).
     * Default ['web']. Empty array = count every channel. Applies to the
     * order-based revenue/orders/refunds figures.
     *
     * We filter in PHP on each order's `sourceName` rather than via the orders
     * query string — Shopify's GraphQL orders search does not reliably honour a
     * `source_name:` filter, and `channelInformation` is null for web orders.
     * The config() default is set here too, so a stale config cache predating
     * config/sync.php still filters to Online Store.
     *
     * @return array<int, string>
     */
    private function allowedSources(): array
    {
        $list = (array) config('sync.shopify.online_store_sources', ['web']);

        return array_values(array_filter(array_map(
            static fn ($s) => strtolower(trim((string) $s)),
            $list
        )));
    }

    /** True when the order's source_name is an allowed channel (or no filter set). */
    private function isAllowedSource(?string $sourceName, array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }

        return in_array(strtolower((string) $sourceName), $allowed, true);
    }

    /** ShopifyQL sales-channel name scoping net_sales (matches the dashboard). */
    private function shopifyqlChannel(): string
    {
        return (string) config('sync.shopify.shopifyql_sales_channel', 'Online Store');
    }

    /**
     * Fetch one day for one (brand × shopify connection) and return a snapshot.
     */
    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        $brand  = $conn->brand;
        $tz     = $brand?->timezone ?? 'UTC';
        $shop   = (string) $conn->external_id;
        $client = $this->makeClient($conn);

        $startLocal = $date->setTimezone($tz)->startOfDay();
        $endLocal   = $startLocal->endOfDay();
        $startUtc   = $startLocal->setTimezone('UTC')->toIso8601String();
        $endUtc     = $endLocal->setTimezone('UTC')->toIso8601String();
        $dateStr    = $startLocal->toDateString();

        $currency = $this->resolveCurrency($conn, $client);

        // Paginated single-day pull. High-volume brands exceed one page easily
        // (Meller does ~3,500 web orders/day), so we MUST follow the cursor —
        // a single page would silently undercount revenue + orders.
        $gql = <<<'GQL'
query OrdersForDay($q: String!, $first: Int!, $after: String) {
  orders(first: $first, query: $q, sortKey: CREATED_AT, after: $after) {
    edges {
      cursor
      node {
        createdAt
        sourceName
        totalPriceSet    { shopMoney { amount currencyCode } }
        totalRefundedSet { shopMoney { amount } }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

        $revenue        = 0.0;
        $refundsAmount  = 0.0;
        $orders         = 0;
        $refundedOrders = 0;
        $allowed        = $this->allowedSources();
        $cursor         = null;
        $pages          = 0;

        // status:any counts open + closed + archived + cancelled orders. Without
        // it Shopify defaults to status:open, so established brands whose orders
        // archive within a day (e.g. Meller) return ~0 here and gross revenue
        // collapses to 0 — even though net_sales (ShopifyQL) is correct. This
        // matches fetchAllSince() and shopify:diagnose.
        $dayQuery = "status:any AND created_at:>='{$startUtc}' AND created_at:<='{$endUtc}'";

        do {
            $data = $client->graphql($gql, [
                'q'     => $dayQuery,
                'first' => self::HISTORY_PAGE_SIZE,
                'after' => $cursor,
            ]);

            $edges = $data['orders']['edges'] ?? [];
            if (! is_array($edges)) {
                break;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];

                // Online Store channel only (or all channels when no filter set).
                if (! $this->isAllowedSource($node['sourceName'] ?? null, $allowed)) {
                    continue;
                }

                $gross  = (float) ($node['totalPriceSet']['shopMoney']['amount'] ?? 0.0);
                $refund = (float) ($node['totalRefundedSet']['shopMoney']['amount'] ?? 0.0);

                $revenue += $gross;
                $orders  += 1;

                if ($refund > 0) {
                    $refundsAmount  += $refund;
                    $refundedOrders += 1;
                }
            }

            $pageInfo = $data['orders']['pageInfo'] ?? [];
            $hasNext  = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor   = (string) ($pageInfo['endCursor'] ?? '');
            $pages++;
        } while ($hasNext && $cursor !== '' && $pages < self::MAX_HISTORY_PAGES);

        if ($pages >= self::MAX_HISTORY_PAGES) {
            Log::warning('Shopify fetch() hit the page cap for a single day.', [
                'brand_id' => $conn->brand_id,
                'shop'     => $shop,
                'date'     => $date->toDateString(),
            ]);
        }

        $revenueNet = round($revenue - $refundsAmount, 2);

        // net_sales: Shopify's own figure via ShopifyQL (one-day window). Null
        // if ShopifyQL is unavailable — missing, not zero.
        $netByDay   = $this->netSalesByDay($client, $dateStr, $dateStr);
        $dayFigures = $netByDay[$dateStr] ?? null;
        $netSales   = $dayFigures['net'] ?? null;
        $totalSales = $dayFigures['total'] ?? null;

        $todayLocal = CarbonImmutable::now($tz)->startOfDay();
        $isComplete = $date->setTimezone($tz)->startOfDay()->lessThan($todayLocal);

        return new MetricSnapshot(
            brandId:        $conn->brand_id,
            platform:       'shopify',
            date:           $date,
            currency:       $currency,
            revenue:        round($revenue, 2),
            revenueNet:     $revenueNet,
            netSales:       $netSales,
            totalSales:     $totalSales,
            orders:         $orders,
            refundsAmount:  round($refundsAmount, 2),
            refundedOrders: $refundedOrders,
            metadata:       ['shop' => $shop],
            isComplete:     $isComplete,
        );
    }

    /**
     * Paginated all-time (or since-a-date) fetch. Used by the manual
     * "Sync now" button so a freshly installed store sees real data
     * immediately.
     *
     * Returns one MetricSnapshot per calendar day keyed by ISO date in
     * the brand's timezone. Today is flagged `isComplete=false`.
     *
     * @return array<string, MetricSnapshot>
     */
    public function fetchAllSince(PlatformConnection $conn, ?CarbonImmutable $since = null): array
    {
        $brand    = $conn->brand;
        $tz       = $brand?->timezone ?? 'UTC';
        $shop     = (string) $conn->external_id;
        $client   = $this->makeClient($conn);
        $currency = $this->resolveCurrency($conn, $client);

        // Build the Shopify search query. `status:any` includes cancelled/closed.
        $queryParts = ['status:any'];
        if ($since !== null) {
            $sinceUtc = $since->setTimezone('UTC')->toIso8601String();
            $queryParts[] = "created_at:>='{$sinceUtc}'";
        }
        $shopifyQuery = implode(' AND ', $queryParts);

        $gql = <<<'GQL'
query OrdersHistory($q: String!, $first: Int!, $after: String) {
  orders(first: $first, query: $q, sortKey: CREATED_AT, after: $after) {
    edges {
      cursor
      node {
        createdAt
        sourceName
        totalPriceSet    { shopMoney { amount currencyCode } }
        totalRefundedSet { shopMoney { amount } }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

        /** @var array<string, array{revenue: float, orders: int, refunds: float, refunded: int}> $byDay */
        $allowed = $this->allowedSources();
        $byDay   = [];
        $cursor  = null;
        $pages   = 0;

        do {
            $data = $client->graphql($gql, [
                'q'     => $shopifyQuery,
                'first' => self::HISTORY_PAGE_SIZE,
                'after' => $cursor,
            ]);

            $edges = $data['orders']['edges'] ?? [];
            if (! is_array($edges)) {
                break;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                if (! $this->isAllowedSource($node['sourceName'] ?? null, $allowed)) {
                    continue;
                }
                $createdAt = (string) ($node['createdAt'] ?? '');
                if ($createdAt === '') {
                    continue;
                }

                // Bucket sales by the order's local creation date.
                $localDate = CarbonImmutable::parse($createdAt)
                    ->setTimezone($tz)
                    ->toDateString();

                if (! isset($byDay[$localDate])) {
                    $byDay[$localDate] = ['revenue' => 0.0, 'orders' => 0, 'refunds' => 0.0, 'refunded' => 0];
                }

                $gross  = (float) ($node['totalPriceSet']['shopMoney']['amount'] ?? 0.0);
                $refund = (float) ($node['totalRefundedSet']['shopMoney']['amount'] ?? 0.0);

                $byDay[$localDate]['revenue'] += $gross;
                $byDay[$localDate]['orders']  += 1;

                if ($refund > 0) {
                    $byDay[$localDate]['refunds']  += $refund;
                    $byDay[$localDate]['refunded'] += 1;
                }
            }

            $pageInfo = $data['orders']['pageInfo'] ?? [];
            $hasNext  = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor   = (string) ($pageInfo['endCursor'] ?? '');
            $pages++;
        } while ($hasNext && $cursor !== '' && $pages < self::MAX_HISTORY_PAGES);

        if ($pages >= self::MAX_HISTORY_PAGES) {
            Log::warning('Shopify history scan hit page cap.', [
                'brand_id' => $conn->brand_id,
                'shop'     => $shop,
                'pages'    => $pages,
            ]);
        }

        // net_sales for the whole range in one ShopifyQL call (grouped by day).
        $todayLocal = CarbonImmutable::now($tz)->toDateString();
        $sinceStr   = $since !== null
            ? $since->setTimezone($tz)->toDateString()
            : (count($byDay) > 0 ? min(array_keys($byDay)) : $todayLocal);
        $netByDay = $this->netSalesByDay($client, $sinceStr, $todayLocal);

        // Days with orders, plus refund-only days ShopifyQL reports a non-zero
        // net for (e.g. a day of pure returns → negative net sales).
        $dates = array_keys($byDay);
        foreach (array_keys($netByDay) as $d) {
            if (! isset($byDay[$d]) && (($netByDay[$d]['net'] ?? 0.0) != 0.0)) {
                $dates[] = $d;
            }
        }
        $dates = array_values(array_unique($dates));

        $snapshots = [];
        foreach ($dates as $date) {
            $totals = $byDay[$date] ?? ['revenue' => 0.0, 'orders' => 0, 'refunds' => 0.0, 'refunded' => 0];

            $revenue    = round($totals['revenue'], 2);
            $refunds    = round($totals['refunds'], 2);
            $revenueNet = round($revenue - $refunds, 2);

            $snapshots[$date] = new MetricSnapshot(
                brandId:        $conn->brand_id,
                platform:       'shopify',
                date:           CarbonImmutable::parse($date, $tz)->startOfDay(),
                currency:       $currency,
                revenue:        $revenue,
                revenueNet:     $revenueNet,
                netSales:       $netByDay[$date]['net'] ?? null,
                totalSales:     $netByDay[$date]['total'] ?? null,
                orders:         $totals['orders'],
                refundsAmount:  $refunds,
                refundedOrders: $totals['refunded'],
                metadata:       ['shop' => $shop],
                isComplete:     $date !== $todayLocal,
            );
        }

        ksort($snapshots);
        return $snapshots;
    }

    /**
     * Public range pull of Shopify's daily net_sales + total_sales (ShopifyQL),
     * for the historical sales backfill (shopify:backfill-sales) that powers the
     * year-over-year comparison.
     *
     * @return array<string, array{net: float, total: ?float}>
     */
    public function salesByDayRange(PlatformConnection $conn, string $sinceStr, string $untilStr): array
    {
        return $this->netSalesByDay($this->makeClient($conn), $sinceStr, $untilStr);
    }

    /**
     * Range pull of Shopify sales grouped by day AND a dimension (country,
     * product, or product category) for the reporting engine's Country and
     * Product reports (feature spec slice 2.1). One ShopifyQL call per dimension.
     *
     * @return array<int, array<string, mixed>>
     */
    public function salesByDimensionRange(PlatformConnection $conn, string $dimension, string $sinceStr, string $untilStr): array
    {
        return $this->groupedSalesByDay($this->makeClient($conn), $dimension, $sinceStr, $untilStr);
    }

    /**
     * ShopifyQL `FROM sales ... GROUP BY day, {dimension}`. Measures are kept to
     * the proven total_sales / net_sales / orders so the only variable is the
     * dimension name — a wrong dimension surfaces as a logged parseError and an
     * empty result (never a fake zero), so the backfill degrades cleanly and the
     * dimension is a one-line fix in the command's allow-list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupedSalesByDay(ShopifyClient $client, string $dimension, string $sinceStr, string $untilStr): array
    {
        // Only [a-z_] — the dimension is a fixed allow-list from the command,
        // never user input, but sanitise anyway so it can't break the QL.
        $dim     = preg_replace('/[^a-z_]/', '', strtolower($dimension)) ?? '';
        $channel = str_replace("'", '', $this->shopifyqlChannel());
        if ($dim === '') {
            return [];
        }

        $ql = "FROM sales SHOW total_sales, net_sales, orders "
            . "GROUP BY day, {$dim} "
            . "SINCE {$sinceStr} UNTIL {$untilStr} "
            . "WHERE sales_channel = '{$channel}' ORDER BY day";

        $gql = <<<'GQL'
query ($q: String!) {
  shopifyqlQuery(query: $q) {
    tableData { columns { name } rows }
    parseErrors
  }
}
GQL;

        try {
            $data = $client->graphql($gql, ['q' => $ql], self::SHOPIFYQL_API_VERSION);
        } catch (Throwable $e) {
            Log::warning('shopify.shopifyql.request_failed', ['error' => $e->getMessage(), 'ql' => $ql]);
            return [];
        }

        $resp = $data['shopifyqlQuery'] ?? null;
        if (! is_array($resp)) {
            return [];
        }
        if (! empty($resp['parseErrors'])) {
            $pe = $resp['parseErrors'];
            Log::warning('shopify.shopifyql.parse_error', [
                'parseErrors' => is_array($pe) ? json_encode($pe) : (string) $pe,
                'ql'          => $ql,
            ]);
            return [];
        }

        $columns = $resp['tableData']['columns'] ?? [];
        $rows    = $resp['tableData']['rows'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        // Two grouping columns (day + the dimension); the rest are measures.
        $metricCols = ['total_sales', 'net_sales', 'orders'];
        $dayCol = null;
        foreach ($columns as $c) {
            $name = (string) ($c['name'] ?? '');
            if ($name !== '' && $name !== $dim && ! in_array($name, $metricCols, true)) {
                $dayCol = $name;
                break;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawDay = $dayCol !== null ? ($row[$dayCol] ?? null) : null;
            $key    = $row[$dim] ?? null;
            if ($rawDay === null || $key === null || (string) $key === '') {
                continue;
            }
            $out[] = [
                'date'   => substr((string) $rawDay, 0, 10),
                'key'    => (string) $key,
                'label'  => (string) $key,
                'total'  => isset($row['total_sales']) ? round((float) $row['total_sales'], 2) : null,
                'net'    => isset($row['net_sales'])   ? round((float) $row['net_sales'], 2)   : null,
                'orders' => isset($row['orders'])      ? (int) $row['orders']                  : null,
                'units'  => null,
            ];
        }

        return $out;
    }

    /**
     * Net sales per day from Shopify's analytics engine (ShopifyQL) — the exact
     * "Net sales" figure behind Analytics > Reports, scoped to the Online Store
     * channel. Dates are grouped in the STORE timezone (ShopifyQL's native
     * grouping), which we assume equals the brand timezone — the dashboard
     * already relies on that alignment.
     *
     * Returns an empty map (so callers store net_sales = null, NOT zero) if
     * ShopifyQL errors, rather than failing the whole sync.
     *
     * @return array<string, array{net: float, total: ?float}>  [Y-m-d => figures]
     */
    private function netSalesByDay(ShopifyClient $client, string $sinceStr, string $untilStr): array
    {
        // Strip quotes so the channel value can't break out of the WHERE literal.
        $channel = str_replace("'", '', $this->shopifyqlChannel());
        $ql = "FROM sales SHOW net_sales, total_sales GROUP BY day "
            . "SINCE {$sinceStr} UNTIL {$untilStr} "
            . "WHERE sales_channel = '{$channel}' ORDER BY day";

        $gql = <<<'GQL'
query ($q: String!) {
  shopifyqlQuery(query: $q) {
    tableData { columns { name } rows }
    parseErrors
  }
}
GQL;

        try {
            $data = $client->graphql($gql, ['q' => $ql], self::SHOPIFYQL_API_VERSION);
        } catch (Throwable $e) {
            Log::warning('shopify.shopifyql.request_failed', ['error' => $e->getMessage(), 'ql' => $ql]);
            return [];
        }

        $resp = $data['shopifyqlQuery'] ?? null;
        if (! is_array($resp)) {
            return [];
        }
        if (! empty($resp['parseErrors'])) {
            $pe = $resp['parseErrors'];
            Log::warning('shopify.shopifyql.parse_error', [
                'parseErrors' => is_array($pe) ? json_encode($pe) : (string) $pe,
                'ql'          => $ql,
            ]);
            return [];
        }

        $columns = $resp['tableData']['columns'] ?? [];
        $rows    = $resp['tableData']['rows'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        // The grouping (day) column is the only non-metric column.
        $metricCols = ['net_sales', 'total_sales'];
        $dayCol = null;
        foreach ($columns as $c) {
            $name = (string) ($c['name'] ?? '');
            if ($name !== '' && ! in_array($name, $metricCols, true)) {
                $dayCol = $name;
                break;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawDay = $dayCol !== null ? ($row[$dayCol] ?? null) : null;
            $ns     = $row['net_sales'] ?? null;
            if ($rawDay === null || $ns === null) {
                continue;
            }
            // Normalise "2026-06-05T00:00:00" / "2026-06-05" → "2026-06-05".
            $day = substr((string) $rawDay, 0, 10);
            $ts  = $row['total_sales'] ?? null;
            $out[$day] = [
                'net'   => round((float) $ns, 2),
                'total' => $ts !== null ? round((float) $ts, 2) : null,
            ];
        }

        return $out;
    }

    /**
     * Build a ShopifyClient for this connection AND register a 401 callback
     * that auto-refreshes the access token. The callback runs at most once
     * per failing call and updates the stored credentials so subsequent jobs
     * pick up the new token without re-OAuth.
     */
    private function makeClient(PlatformConnection $conn): ShopifyClient
    {
        $accessToken = (string) ($conn->credentials['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Shopify connection is missing access_token.');
        }

        $shop   = (string) $conn->external_id;
        $client = new ShopifyClient($shop, $accessToken);

        $oauth = $this->oauth;
        $client->onUnauthorized(function () use ($conn, $oauth): ?string {
            // Refresh path 1: we have a refresh_token (expiring offline-access flow).
            // Refresh path 2: nothing we can do — surface the 401 to the caller.
            $refresh = (string) ($conn->credentials['refresh_token'] ?? '');
            if ($refresh === '') {
                Log::warning('shopify.client.401_no_refresh_token', [
                    'connection_id' => $conn->id,
                    'shop'          => $conn->external_id,
                ]);
                return null;
            }

            try {
                $fresh = $oauth->refreshAccessToken($conn);
            } catch (Throwable $e) {
                Log::warning('shopify.client.refresh_failed', [
                    'connection_id' => $conn->id,
                    'shop'          => $conn->external_id,
                    'error'         => $e->getMessage(),
                ]);
                return null;
            }

            // Persist the refreshed creds onto the connection so the NEXT sync
            // job starts with the rotated token — no re-OAuth required.
            $conn->forceFill(['credentials' => $fresh])->save();
            $conn->refresh();

            $newToken = (string) ($fresh['access_token'] ?? '');
            return $newToken !== '' ? $newToken : null;
        });

        return $client;
    }

    /**
     * Look up the shop's currencyCode. Cached onto connection.metadata once.
     */
    private function resolveCurrency(PlatformConnection $conn, ShopifyClient $client): string
    {
        $cached = $conn->metadata['currency'] ?? null;
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $data = $client->graphql('{ shop { currencyCode } }');
        $code = (string) ($data['shop']['currencyCode'] ?? '');
        if ($code === '') {
            throw new RuntimeException('Shopify shop query returned no currencyCode.');
        }

        $metadata = $conn->metadata ?? [];
        $metadata['currency'] = $code;
        $conn->forceFill(['metadata' => $metadata])->save();

        return $code;
    }
}
