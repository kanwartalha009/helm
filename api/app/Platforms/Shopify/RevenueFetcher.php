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
 * Pulls orders + refunds from Shopify GraphQL and assembles MetricSnapshots.
 * Delegates the HTTP work to ShopifyClient.
 *
 * Field definitions:
 *   - revenue        = sum of order.totalPriceSet.shopMoney.amount
 *                      (gross order value at creation — tax + shipping +
 *                      discounts already applied, refunds NOT subtracted).
 *                      Drives the dashboard "Total revenue" (gross) toggle.
 *   - net_sales      = Σ (currentSubtotalPriceSet − product tax) for orders
 *                      created in the window, MINUS the ex-tax value of every
 *                      refund processed in the window. Mirrors Shopify's "Net
 *                      sales": product revenue after discounts, excluding tax,
 *                      shipping, duties, and returns. Default dashboard metric.
 *                      product tax = currentTotalTaxSet − shipping-line tax
 *                      (shipping tax is excluded because the subtotal never
 *                      carried shipping; duties never enter the subtotal).
 *                      We use the CURRENT (after-returns) subtotal/tax fields on
 *                      purpose — they match Shopify's reported Net sales. The
 *                      before-returns totalTaxSet over-removes tax (~€686 on
 *                      Flabelus 2026-06-05) and lands the figure low. Verified
 *                      against `shopify:diagnose` (€20,637.69 vs Shopify
 *                      €20,631.32, a 0.03% rounding gap).
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
 * Refund attribution: net_sales dates returns to the REFUND'S createdAt (the
 * day Shopify processes the refund), NOT the original order date — this is how
 * Shopify's analytics computes Net sales, and it overrides the original spec
 * §15.2 order-date policy (changed 2026-06-06 at the client's request; every
 * brand must be re-synced for the change to take effect). `revenue` and
 * `refunds_amount` stay order-dated for backward compatibility.
 */
final class RevenueFetcher
{
    /** Phase-1 single-page limit per the spec. We log + flag if a day exceeds it. */
    private const PAGE_SIZE = 100;

    /** Bigger page size for the historical paginated scan — Shopify caps at 250. */
    private const HISTORY_PAGE_SIZE = 250;

    /** Hard safety cap on cursor pages — 200 × 250 = 50,000 orders/sync. */
    private const MAX_HISTORY_PAGES = 200;

    public function __construct(private readonly OAuthService $oauth) {}

    /**
     * Order source_names that count as the Online Store channel (lowercased).
     * Default ['web']. Empty array = count every channel.
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

        $currency = $this->resolveCurrency($conn, $client);

        $gql = <<<'GQL'
query OrdersForDay($q: String!, $first: Int!) {
  orders(first: $first, query: $q, sortKey: CREATED_AT) {
    edges {
      node {
        id
        createdAt
        sourceName
        totalPriceSet    { shopMoney { amount currencyCode } }
        currentSubtotalPriceSet { shopMoney { amount } }
        currentTotalTaxSet      { shopMoney { amount } }
        totalRefundedSet { shopMoney { amount } }
        shippingLines(first: 20) {
          edges { node { taxLines { priceSet { shopMoney { amount } } } } }
        }
      }
    }
    pageInfo { hasNextPage }
  }
}
GQL;

        $data = $client->graphql($gql, [
            'q'     => "created_at:>='{$startUtc}' AND created_at:<='{$endUtc}'",
            'first' => self::PAGE_SIZE,
        ]);

        $edges = $data['orders']['edges'] ?? [];
        if (! is_array($edges)) {
            $edges = [];
        }

        $revenue        = 0.0;
        $exTaxBase      = 0.0;   // Σ (product subtotal − product tax), before returns
        $refundsAmount  = 0.0;
        $orders         = 0;
        $refundedOrders = 0;
        $allowed        = $this->allowedSources();

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];

            // Online Store channel only (or all channels when no filter set).
            if (! $this->isAllowedSource($node['sourceName'] ?? null, $allowed)) {
                continue;
            }

            $gross    = (float) ($node['totalPriceSet']['shopMoney']['amount'] ?? 0.0);
            $subtotal = (float) ($node['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0.0);
            $totalTax = (float) ($node['currentTotalTaxSet']['shopMoney']['amount'] ?? 0.0);
            $shipTax  = $this->shippingTax($node);
            $refund   = (float) ($node['totalRefundedSet']['shopMoney']['amount'] ?? 0.0);

            // Net-sales base = product subtotal minus PRODUCT tax only. Total
            // order tax includes tax charged on shipping, which the subtotal
            // never contained, so shipping tax is excluded here.
            $exTaxBase += $subtotal - ($totalTax - $shipTax);
            $revenue   += $gross;
            $orders    += 1;

            if ($refund > 0) {
                $refundsAmount  += $refund;
                $refundedOrders += 1;
            }
        }

        if (($data['orders']['pageInfo']['hasNextPage'] ?? false) === true) {
            Log::warning('Shopify orders page is full — pagination not yet implemented for fetchDay().', [
                'brand_id' => $conn->brand_id,
                'shop'     => $shop,
                'date'     => $date->toDateString(),
            ]);
        }

        $revenueNet = round($revenue - $refundsAmount, 2);

        // Returns are attributed to the date the REFUND was created (Shopify's
        // "Net sales" convention), not the original order date — so we query
        // refunds separately rather than netting each order's own refunds.
        $refundDateReturns = $this->fetchRefundDateReturns($client, $tz, $date, $allowed);
        $netSales          = round($exTaxBase - $refundDateReturns, 2);

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
        id
        createdAt
        sourceName
        totalPriceSet    { shopMoney { amount currencyCode } }
        currentSubtotalPriceSet { shopMoney { amount } }
        currentTotalTaxSet      { shopMoney { amount } }
        totalRefundedSet { shopMoney { amount } }
        shippingLines(first: 20) {
          edges { node { taxLines { priceSet { shopMoney { amount } } } } }
        }
        refunds {
          createdAt
          refundLineItems(first: 100) {
            edges { node { subtotalSet { shopMoney { amount } } } }
          }
        }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

        /** @var array<string, array{revenue: float, exbase: float, orders: int, refunds: float, refunded: int}> $byDay */
        $allowed      = $this->allowedSources();
        $byDay        = [];
        $returnsByDay = [];   // ex-tax product returns, keyed by REFUND date
        $cursor       = null;
        $pages        = 0;

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
                    $byDay[$localDate] = ['revenue' => 0.0, 'exbase' => 0.0, 'orders' => 0, 'refunds' => 0.0, 'refunded' => 0];
                }

                $gross    = (float) ($node['totalPriceSet']['shopMoney']['amount'] ?? 0.0);
                $subtotal = (float) ($node['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0.0);
                $totalTax = (float) ($node['currentTotalTaxSet']['shopMoney']['amount'] ?? 0.0);
                $shipTax  = $this->shippingTax($node);
                $refund   = (float) ($node['totalRefundedSet']['shopMoney']['amount'] ?? 0.0);

                $byDay[$localDate]['revenue'] += $gross;
                $byDay[$localDate]['exbase']  += $subtotal - ($totalTax - $shipTax);
                $byDay[$localDate]['orders']  += 1;

                if ($refund > 0) {
                    $byDay[$localDate]['refunds']  += $refund;
                    $byDay[$localDate]['refunded'] += 1;
                }

                // Bucket returns by the date each REFUND was created.
                foreach (($node['refunds'] ?? []) as $r) {
                    $refundCreated = (string) ($r['createdAt'] ?? '');
                    if ($refundCreated === '') {
                        continue;
                    }
                    $refundDate = CarbonImmutable::parse($refundCreated)
                        ->setTimezone($tz)
                        ->toDateString();
                    foreach (($r['refundLineItems']['edges'] ?? []) as $rli) {
                        $returnsByDay[$refundDate] = ($returnsByDay[$refundDate] ?? 0.0)
                            + (float) ($rli['node']['subtotalSet']['shopMoney']['amount'] ?? 0.0);
                    }
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

        // Assemble one snapshot per day. A day can appear in the returns map
        // with no sales (refunds processed on a day that had no orders) — those
        // still get a row, with negative net sales, matching Shopify.
        $todayLocal = CarbonImmutable::now($tz)->toDateString();
        $allDates   = array_values(array_unique(array_merge(array_keys($byDay), array_keys($returnsByDay))));
        $snapshots  = [];
        foreach ($allDates as $date) {
            $totals = $byDay[$date] ?? ['revenue' => 0.0, 'exbase' => 0.0, 'orders' => 0, 'refunds' => 0.0, 'refunded' => 0];

            $revenue    = round($totals['revenue'], 2);
            $refunds    = round($totals['refunds'], 2);
            $revenueNet = round($revenue - $refunds, 2);
            $netSales   = round($totals['exbase'] - ($returnsByDay[$date] ?? 0.0), 2);

            $snapshots[$date] = new MetricSnapshot(
                brandId:        $conn->brand_id,
                platform:       'shopify',
                date:           CarbonImmutable::parse($date, $tz)->startOfDay(),
                currency:       $currency,
                revenue:        $revenue,
                revenueNet:     $revenueNet,
                netSales:       $netSales,
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

    /** Σ tax charged on shipping lines for one order node (before returns). */
    private function shippingTax(array $node): float
    {
        $sum = 0.0;
        foreach (($node['shippingLines']['edges'] ?? []) as $slEdge) {
            foreach (($slEdge['node']['taxLines'] ?? []) as $tl) {
                $sum += (float) ($tl['priceSet']['shopMoney']['amount'] ?? 0.0);
            }
        }

        return $sum;
    }

    /**
     * Σ ex-tax product value of every refund whose createdAt falls on $date
     * (in the brand timezone), across orders updated on/after that day. This is
     * the "returns" component of Shopify's Net sales — attributed by refund
     * date, not by the original order date.
     *
     * Used by the per-day fetch(). fetchAllSince() does its own refund-date
     * bucketing inline during the history scan.
     */
    private function fetchRefundDateReturns(
        ShopifyClient $client,
        string $tz,
        CarbonImmutable $date,
        array $allowed
    ): float {
        $startLocal = $date->setTimezone($tz)->startOfDay();
        $startUtc   = $startLocal->setTimezone('UTC')->toIso8601String();
        $targetDate = $startLocal->toDateString();

        $gql = <<<'GQL'
query RefundsSince($q: String!, $first: Int!, $after: String) {
  orders(first: $first, query: $q, sortKey: UPDATED_AT, after: $after) {
    edges {
      node {
        sourceName
        refunds {
          createdAt
          refundLineItems(first: 100) {
            edges { node { subtotalSet { shopMoney { amount } } } }
          }
        }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

        $q       = "status:any AND updated_at:>='{$startUtc}'";
        $returns = 0.0;
        $cursor  = null;
        $pages   = 0;

        do {
            $data  = $client->graphql($gql, [
                'q'     => $q,
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
                foreach (($node['refunds'] ?? []) as $r) {
                    $refundCreated = (string) ($r['createdAt'] ?? '');
                    if ($refundCreated === '') {
                        continue;
                    }
                    $refundDate = CarbonImmutable::parse($refundCreated)
                        ->setTimezone($tz)
                        ->toDateString();
                    if ($refundDate !== $targetDate) {
                        continue;
                    }
                    foreach (($r['refundLineItems']['edges'] ?? []) as $rli) {
                        $returns += (float) ($rli['node']['subtotalSet']['shopMoney']['amount'] ?? 0.0);
                    }
                }
            }

            $pageInfo = $data['orders']['pageInfo'] ?? [];
            $hasNext  = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor   = (string) ($pageInfo['endCursor'] ?? '');
            $pages++;
        } while ($hasNext && $cursor !== '' && $pages < self::MAX_HISTORY_PAGES);

        return round($returns, 2);
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
