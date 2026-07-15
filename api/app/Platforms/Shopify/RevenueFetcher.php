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
// Not final: SyncBrandDayJob type-hints this concrete class, and the job's
// lifecycle tests need a test double at that seam (tests/Feature/SyncBrandDayJobTest).
class RevenueFetcher
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

    // ShopifyQL caps results at 1000 rows when no LIMIT is given (its documented
    // default), silently dropping the tail. The commerce backfill groups by
    // day × dimension, so it overrides that with an explicit ceiling and the
    // command chunks by month to stay well under it. 10k < the 25k Admin API
    // pagination ceiling.
    private const SHOPIFYQL_ROW_LIMIT = 10000;

    /**
     * M0 (2026-07-14): bounded Guzzle timeout for the ShopifyQL call made
     * synchronously inside a report request (customersByMonthRange, called
     * from MonthlyReport::newVsExistingSection). ShopifyClient's normal
     * default is 30s, meant for background sync jobs; a report request
     * blocking a web worker for up to 30s (x2, since the section pulls a
     * current + a comparison window) was the confirmed root cause of the
     * new-polinesia monthly report freeze. A failed/slow call here degrades
     * to needs_source (missing != zero) rather than hanging the request.
     */
    private const REPORT_CONTEXT_TIMEOUT_SECS = 12;

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
    /**
     * THE DASHBOARD'S FAST PATH. One ShopifyQL call. No order pagination.
     *
     * ══ WHY ══
     * This used to page through EVERY ORDER of the day to compute gross revenue and the order
     * count. On Meller (~3,500 web orders/day) that took **74 seconds** — measured on the Sync
     * health page, against 1–2s for every other brand and platform. Bosco was manually syncing
     * each morning and watching the dashboard fill one brand at a time.
     *
     * Every number the dashboard actually shows — total_sales, net_sales, refunds, orders —
     * already comes from ONE ShopifyQL `FROM sales` call. `netSalesByDay()` has been fetching the
     * order count all along and `fetch()` was throwing it away in favour of the 74-second scan.
     *
     * So the scan is gone from the hot path. Gross `revenue` / `revenue_net` / `refunded_orders`
     * are the only figures it uniquely produced; they are now filled by the enrichment job
     * (fetchGrossDay below) and OMITTED here — omitted, not nulled, so phase 1 can never erase
     * what phase 2 wrote. See MetricSnapshot::$omitFields.
     */
    public function fetch(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        $brand  = $conn->brand;
        $tz     = $brand?->timezone ?? 'UTC';
        $shop   = (string) $conn->external_id;
        $client = $this->makeClient($conn);

        $dateStr  = $date->setTimezone($tz)->startOfDay()->toDateString();
        $currency = $this->resolveCurrency($conn, $client);

        $dayFigures = $this->netSalesByDay($client, $dateStr, $dateStr)[$dateStr] ?? null;

        $todayLocal = CarbonImmutable::now($tz)->startOfDay();
        $isComplete = $date->setTimezone($tz)->startOfDay()->lessThan($todayLocal);

        // ShopifyQL returned nothing for the day. That is NOT a €0 day — it is a day we know
        // nothing about (a hiccup, or a store that didn't exist yet). Leave every figure null and
        // let the dashboard render "—". The old code could infer a confirmed zero because it also
        // had the order scan to corroborate; without the scan we cannot, so we don't pretend to.
        if ($dayFigures === null) {
            return new MetricSnapshot(
                brandId:    $conn->brand_id,
                platform:   'shopify',
                date:       $date,
                currency:   $currency,
                metadata:   ['shop' => $shop],
                isComplete: $isComplete,
                omitFields: self::GROSS_FIELDS,
            );
        }

        return new MetricSnapshot(
            brandId:       $conn->brand_id,
            platform:      'shopify',
            date:          $date,
            currency:      $currency,
            netSales:      $dayFigures['net'] ?? null,
            totalSales:    $dayFigures['total'] ?? null,
            // Straight from ShopifyQL — reliable on high-volume brands, where paging thousands of
            // orders was both slow and fragile.
            orders:        $dayFigures['orders'] ?? null,
            refundsAmount: $dayFigures['returns'] ?? null,
            metadata:      ['shop' => $shop],
            isComplete:    $isComplete,
            omitFields:    self::GROSS_FIELDS,
        );
    }

    /** Columns only the order-by-order scan can produce. Filled by the enrichment job. */
    private const GROSS_FIELDS = ['revenue', 'revenue_net', 'refunded_orders'];

    /**
     * The order-by-order scan, now OFF the dashboard's critical path.
     *
     * Produces gross revenue (Σ order totalPrice for the allowed sales channels), the refunded
     * order count, and a refund total used only as a fallback. Slow by nature — Meller pages
     * thousands of orders — which is exactly why it runs in enrichment, after every brand's
     * headline number is already on screen.
     *
     * @return array{revenue: float, revenueNet: float, refundedOrders: int, refundsAmount: float}|null
     *         null when the scan could not be completed — the caller must then write NOTHING
     *         rather than a zero.
     */
    public function fetchGrossDay(PlatformConnection $conn, CarbonImmutable $date): ?array
    {
        $brand  = $conn->brand;
        $tz     = $brand?->timezone ?? 'UTC';
        $client = $this->makeClient($conn);

        $startLocal = $date->setTimezone($tz)->startOfDay();
        $endLocal   = $startLocal->endOfDay();
        $startUtc   = $startLocal->setTimezone('UTC')->toIso8601String();
        $endUtc     = $endLocal->setTimezone('UTC')->toIso8601String();
        $dateStr    = $startLocal->toDateString();

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
            // We stopped short of the end, so the totals are UNDERCOUNTS. Returning them would
            // silently understate a big brand's gross revenue. Return null: the caller writes
            // nothing, and the previous value (or "—") stands.
            Log::warning('Shopify gross scan hit the page cap for a single day — figures discarded.', [
                'brand_id' => $conn->brand_id,
                'date'     => $dateStr,
            ]);

            return null;
        }

        // Refunds: prefer Shopify's own `returns` (the exact figure total_sales nets out) over the
        // scan — one consistent source, reliable on high-volume brands. The scan's own total is
        // the fallback when ShopifyQL has no row for the day.
        $returns = $this->netSalesByDay($client, $dateStr, $dateStr)[$dateStr]['returns'] ?? null;
        if ($returns !== null) {
            $refundsAmount = (float) $returns;
        }

        return [
            'revenue'        => round($revenue, 2),
            'revenueNet'     => round($revenue - $refundsAmount, 2),
            'refundedOrders' => $refundedOrders,
            'refundsAmount'  => round($refundsAmount, 2),
        ];
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

        // Emit a row for EVERY day in the scanned window, not just days that had
        // orders. A low-volume brand's zero-order day is a CONFIRMED zero (the
        // order scan and the ShopifyQL call both succeeded above), so it must
        // land as a complete €0 / 0-order row — otherwise it shows as a
        // perpetual "Partial" that never updates, because the next sync skips it
        // again for having no orders. Missing ≠ zero still holds: a FAILED scan
        // throws before this point and writes nothing, so we only ever zero-fill
        // a window we actually read end to end.
        $dates  = [];
        $cursor = CarbonImmutable::parse($sinceStr, $tz)->startOfDay();
        $endDay = CarbonImmutable::parse($todayLocal, $tz)->startOfDay();
        while ($cursor->lessThanOrEqualTo($endDay)) {
            $dates[] = $cursor->toDateString();
            $cursor  = $cursor->addDay();
        }

        $snapshots = [];
        foreach ($dates as $date) {
            $totals = $byDay[$date] ?? ['revenue' => 0.0, 'orders' => 0, 'refunds' => 0.0, 'refunded' => 0];

            $revenue = round($totals['revenue'], 2);

            // Refunds from Shopify's own `returns` (the figure total_sales nets
            // out — consistent + reliable), falling back to the order-scan amount
            // only when ShopifyQL didn't return this day.
            $refunds    = $netByDay[$date]['returns'] ?? round($totals['refunds'], 2);
            $revenueNet = round($revenue - $refunds, 2);

            // A day with no orders AND no ShopifyQL sales row is a confirmed zero
            // (the whole window was scanned), so store 0 — not null — and it
            // renders as €0 complete rather than an empty "—". A day that DID have
            // orders but no ShopifyQL row keeps null (unknown total) as before.
            $net   = $netByDay[$date]['net']   ?? (isset($byDay[$date]) ? null : 0.0);
            $total = $netByDay[$date]['total'] ?? (isset($byDay[$date]) ? null : 0.0);

            $snapshots[$date] = new MetricSnapshot(
                brandId:        $conn->brand_id,
                platform:       'shopify',
                date:           CarbonImmutable::parse($date, $tz)->startOfDay(),
                currency:       $currency,
                revenue:        $revenue,
                revenueNet:     $revenueNet,
                netSales:       $net,
                totalSales:     $total,
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

        // Try the EXTENDED metric set first — it adds gross units
        // (quantity_ordered = Bosco's "Ordered_Quantity", does NOT subtract
        // returns) and returns, for the Inventory Intelligence report. If a store
        // or API version rejects a metric name the whole query parseErrors, so we
        // fall back to the PROVEN base set: the Country / Product / Category
        // backfill must never regress just because units aren't available. The
        // fallback is logged, so a wrong metric name is a one-line fix.
        $extended = 'total_sales, net_sales, orders, quantity_ordered, returns';
        $base     = 'total_sales, net_sales, orders';

        $res = $this->runGroupedSalesQuery($client, $dim, $channel, $extended, $sinceStr, $untilStr)
            ?? $this->runGroupedSalesQuery($client, $dim, $channel, $base, $sinceStr, $untilStr);
        if ($res === null) {
            return [];
        }
        [$columns, $rows] = $res;

        // Two grouping columns (day + the dimension); the rest are measures.
        $metricCols = ['total_sales', 'net_sales', 'orders', 'quantity_ordered', 'returns'];
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
                'date'    => substr((string) $rawDay, 0, 10),
                'key'     => (string) $key,
                'label'   => (string) $key,
                'total'   => isset($row['total_sales'])      ? round((float) $row['total_sales'], 2)         : null,
                'net'     => isset($row['net_sales'])        ? round((float) $row['net_sales'], 2)           : null,
                'orders'  => isset($row['orders'])           ? (int) $row['orders']                          : null,
                // Gross units ordered (before returns) — Bosco's "Uds".
                'units'   => isset($row['quantity_ordered']) ? (int) round((float) $row['quantity_ordered']) : null,
                // Shopify reports `returns` as a negative; store the positive
                // magnitude so revenue-before-returns = total_sales + refunds.
                'refunds' => isset($row['returns'])          ? round(abs((float) $row['returns']), 2)        : null,
            ];
        }

        return $out;
    }

    /**
     * One `FROM sales ... GROUP BY day, {dim}` ShopifyQL call. Returns
     * [columns, rows] on success (rows may legitimately be empty), or NULL on a
     * transport failure or a parseError — the caller reads NULL as "retry with a
     * smaller metric set" so an unsupported metric can't wipe the whole backfill.
     *
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}|null
     */
    private function runGroupedSalesQuery(ShopifyClient $client, string $dim, string $channel, string $show, string $sinceStr, string $untilStr): ?array
    {
        $ql = "FROM sales SHOW {$show} "
            . "GROUP BY day, {$dim} "
            . "SINCE {$sinceStr} UNTIL {$untilStr} "
            . "WHERE sales_channel = '{$channel}' ORDER BY day "
            . "LIMIT " . self::SHOPIFYQL_ROW_LIMIT;

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

            return null;
        }

        $resp = $data['shopifyqlQuery'] ?? null;
        if (! is_array($resp)) {
            return null;
        }
        if (! empty($resp['parseErrors'])) {
            $pe = $resp['parseErrors'];
            Log::warning('shopify.shopifyql.parse_error', [
                'parseErrors' => is_array($pe) ? json_encode($pe) : (string) $pe,
                'ql'          => $ql,
            ]);

            return null;
        }

        $columns = $resp['tableData']['columns'] ?? [];
        $rows    = $resp['tableData']['rows'] ?? [];
        if (! is_array($rows) || ! is_array($columns)) {
            return null;
        }

        return [$columns, $rows];
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
     * Orders comes from the same ShopifyQL aggregate as revenue (not the
     * order-by-order pagination), so high-volume brands like Meller — where
     * paging thousands of orders/day is fragile — still get a reliable, fast
     * count that's always consistent with the revenue on the same row.
     *
     * `returns` is the refund magnitude that total_sales already nets out. We
     * keep it (as a positive number) so the dashboard can show "Total sales +
     * refunds" — revenue gross of returns — from one consistent source.
     *
     * @return array<string, array{net: float, total: ?float, orders: ?int, returns: ?float}>  [Y-m-d => figures]
     */
    private function netSalesByDay(ShopifyClient $client, string $sinceStr, string $untilStr): array
    {
        // Strip quotes so the channel value can't break out of the WHERE literal.
        $channel = str_replace("'", '', $this->shopifyqlChannel());
        $ql = "FROM sales SHOW net_sales, total_sales, orders, returns GROUP BY day "
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
        $metricCols = ['net_sales', 'total_sales', 'orders', 'returns'];
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
            $od  = $row['orders'] ?? null;
            $rt  = $row['returns'] ?? null;
            $out[$day] = [
                'net'     => round((float) $ns, 2),
                'total'   => $ts !== null ? round((float) $ts, 2) : null,
                'orders'  => $od !== null ? (int) $od : null,
                // Positive refund magnitude (Shopify reports returns as the
                // amount total_sales already subtracted) — sign-normalised.
                'returns' => $rt !== null ? round(abs((float) $rt), 2) : null,
            ];
        }

        return $out;
    }

    /**
     * Range pull of the ShopifyQL web funnel grouped by day AND a dimension
     * (session_country for §10, landing_page_path for §11): sessions → cart
     * additions → reached checkout → completed checkout per (day, segment). These
     * are additive counts, so they're stored daily and summed to the month. One
     * ShopifyQL `FROM sessions` call; degrades to [] + a logged parseError on
     * failure (never a fake zero).
     *
     * @return array<int, array<string, mixed>>
     */
    public function funnelByDimensionRange(PlatformConnection $conn, string $dimension, string $sinceStr, string $untilStr): array
    {
        return $this->groupedFunnelByDay($this->makeClient($conn), $dimension, $sinceStr, $untilStr);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupedFunnelByDay(ShopifyClient $client, string $dimension, string $sinceStr, string $untilStr): array
    {
        // Fixed allow-list from the caller, but sanitise anyway (never user input).
        $dim = preg_replace('/[^a-z_]/', '', strtolower($dimension)) ?? '';
        if ($dim === '') {
            return [];
        }

        $ql = 'FROM sessions SHOW sessions, sessions_with_cart_additions, sessions_that_reached_checkout, sessions_that_completed_checkout '
            . "GROUP BY day, {$dim} "
            . "SINCE {$sinceStr} UNTIL {$untilStr} "
            . 'ORDER BY day '
            . 'LIMIT ' . self::SHOPIFYQL_ROW_LIMIT;

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
            Log::warning('shopify.shopifyql.parse_error', ['parseErrors' => is_array($pe) ? json_encode($pe) : (string) $pe, 'ql' => $ql]);

            return [];
        }

        $columns = $resp['tableData']['columns'] ?? [];
        $rows    = $resp['tableData']['rows'] ?? [];
        if (! is_array($rows) || ! is_array($columns)) {
            return [];
        }

        $metricCols = ['sessions', 'sessions_with_cart_additions', 'sessions_that_reached_checkout', 'sessions_that_completed_checkout'];
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
                'date'               => substr((string) $rawDay, 0, 10),
                'segment_key'        => (string) $key,
                'segment_label'      => (string) $key,
                'sessions'           => isset($row['sessions']) ? (int) round((float) $row['sessions']) : 0,
                'cart_additions'     => isset($row['sessions_with_cart_additions']) ? (int) round((float) $row['sessions_with_cart_additions']) : 0,
                'reached_checkout'   => isset($row['sessions_that_reached_checkout']) ? (int) round((float) $row['sessions_that_reached_checkout']) : 0,
                'completed_checkout' => isset($row['sessions_that_completed_checkout']) ? (int) round((float) $row['sessions_that_completed_checkout']) : 0,
            ];
        }

        return $out;
    }

    /**
     * Per-MONTH customer aggregates from ShopifyQL `sales`: total/net sales,
     * orders, and the new-vs-returning counts (`customers`, `returning_customers`;
     * `new = customers − returning`). Month-grouped on purpose — unique customers
     * don't decompose to days (a buyer on two days is one customer that month), so
     * this can't ride the daily sync; the monthly report calls it live at build.
     * There is NO customer_type dimension on `sales` (verified via
     * shopify:diagnose-customer-type), so revenue can't be split by customer type
     * — only these aggregate counts are available. Degrades to [] on failure.
     *
     * @return array<string, array{total: ?float, net: ?float, orders: ?int, customers: ?int, returning: ?int}>  [Y-m => figures]
     */
    public function customersByMonthRange(PlatformConnection $conn, string $sinceStr, string $untilStr): array
    {
        // M0: bounded timeout — this call runs synchronously inside a report
        // request, not a background job. See REPORT_CONTEXT_TIMEOUT_SECS doc.
        $client  = $this->makeClient($conn, timeoutSeconds: self::REPORT_CONTEXT_TIMEOUT_SECS);
        $channel = str_replace("'", '', $this->shopifyqlChannel());
        $ql = 'FROM sales SHOW net_sales, total_sales, orders, customers, returning_customers GROUP BY month '
            . "SINCE {$sinceStr} UNTIL {$untilStr} "
            . "WHERE sales_channel = '{$channel}' ORDER BY month";

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
            Log::warning('shopify.shopifyql.parse_error', ['parseErrors' => is_array($pe) ? json_encode($pe) : (string) $pe, 'ql' => $ql]);

            return [];
        }

        $columns = $resp['tableData']['columns'] ?? [];
        $rows    = $resp['tableData']['rows'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $metricCols = ['net_sales', 'total_sales', 'orders', 'customers', 'returning_customers'];
        $monthCol = null;
        foreach ($columns as $c) {
            $name = (string) ($c['name'] ?? '');
            if ($name !== '' && ! in_array($name, $metricCols, true)) {
                $monthCol = $name;
                break;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawMonth = $monthCol !== null ? ($row[$monthCol] ?? null) : null;
            if ($rawMonth === null) {
                continue;
            }
            $ym = substr((string) $rawMonth, 0, 7); // "2026-06-01" / "2026-06" → "2026-06"
            $out[$ym] = [
                'total'     => isset($row['total_sales']) ? round((float) $row['total_sales'], 2) : null,
                'net'       => isset($row['net_sales']) ? round((float) $row['net_sales'], 2) : null,
                'orders'    => isset($row['orders']) ? (int) $row['orders'] : null,
                'customers' => isset($row['customers']) ? (int) $row['customers'] : null,
                'returning' => isset($row['returning_customers']) ? (int) $row['returning_customers'] : null,
            ];
        }

        return $out;
    }

    /**
     * Inventory stock + sell-through for one dimension (product_title or
     * product_type) over a trailing window — powers the dead-stock report. One
     * ShopifyQL `FROM inventory` call, one row per dimension value (no day
     * grouping). Degrades to [] + a logged parseError on failure, never a fake
     * zero. Dimensions/fields verified against the ShopifyQL inventory schema.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inventoryByDimension(PlatformConnection $conn, string $dimension, string $sinceStr, string $untilStr, int $limit = 200): array
    {
        return $this->inventoryGrouped($this->makeClient($conn), $dimension, $sinceStr, $untilStr, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inventoryGrouped(ShopifyClient $client, string $dimension, string $sinceStr, string $untilStr, int $limit): array
    {
        $dim = preg_replace('/[^a-z_]/', '', strtolower($dimension)) ?? '';
        if ($dim === '') {
            return [];
        }

        $ql = "FROM inventory SHOW ending_inventory_units, inventory_units_sold, sell_through_rate "
            . "GROUP BY {$dim} "
            . "SINCE {$sinceStr} UNTIL {$untilStr} "
            . "ORDER BY ending_inventory_units DESC "
            . "LIMIT " . max(1, $limit);

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

        $rows = $resp['tableData']['rows'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $row[$dim] ?? null;
            if ($key === null || (string) $key === '') {
                continue;
            }
            $out[] = [
                'key'               => (string) $key,
                'label'             => (string) $key,
                'ending_units'      => isset($row['ending_inventory_units']) ? (int) $row['ending_inventory_units'] : null,
                'units_sold'        => isset($row['inventory_units_sold']) ? (int) $row['inventory_units_sold'] : null,
                'sell_through_rate' => isset($row['sell_through_rate']) && is_numeric($row['sell_through_rate']) ? (float) $row['sell_through_rate'] : null,
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
    private function makeClient(PlatformConnection $conn, ?int $timeoutSeconds = null): ShopifyClient
    {
        $accessToken = (string) ($conn->credentials['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Shopify connection is missing access_token.');
        }

        $shop   = (string) $conn->external_id;
        $client = new ShopifyClient($shop, $accessToken, timeoutSeconds: $timeoutSeconds);

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
