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
 * Revenue / refund fields (chosen to match the dashboard "total sales" and
 * "total refunds" columns):
 *   - revenue        = sum of order.totalPriceSet.shopMoney.amount
 *                      (gross order value at creation — tax + shipping +
 *                      discounts already applied, refunds NOT subtracted)
 *   - refunds_amount = sum of order.totalRefundedSet.shopMoney.amount
 *                      (aggregated by Shopify across every refund on the order;
 *                      saves us a nested traversal)
 *   - revenue_net    = revenue - refunds_amount
 *   - orders         = count of orders created in the window
 *   - refunded_orders = count of orders with totalRefundedSet > 0
 *
 * IMPORTANT: do NOT use currentTotalPriceSet for `revenue`. That field reflects
 * the order's current state — refunds are already excluded — so subtracting
 * refunds again gives you net-of-refunds-twice.
 *
 * Refund attribution policy: a refund is dated to the ORIGINAL ORDER'S
 * createdAt (not the refund's own createdAt). This matches spec §15.2.
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
        totalPriceSet      { shopMoney { amount currencyCode } }
        totalRefundedSet   { shopMoney { amount } }
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
        $refundsAmount  = 0.0;
        $orders         = 0;
        $refundedOrders = 0;

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];

            $gross  = (float) ($node['totalPriceSet']['shopMoney']['amount'] ?? 0.0);
            $refund = (float) ($node['totalRefundedSet']['shopMoney']['amount'] ?? 0.0);

            $revenue += $gross;
            $orders  += 1;

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

        $todayLocal = CarbonImmutable::now($tz)->startOfDay();
        $isComplete = $date->setTimezone($tz)->startOfDay()->lessThan($todayLocal);

        return new MetricSnapshot(
            brandId:        $conn->brand_id,
            platform:       'shopify',
            date:           $date,
            currency:       $currency,
            revenue:        round($revenue, 2),
            revenueNet:     $revenueNet,
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
        totalPriceSet    { shopMoney { amount currencyCode } }
        totalRefundedSet { shopMoney { amount } }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

        /** @var array<string, array{revenue: float, orders: int, refunds: float, refunded: int}> $byDay */
        $byDay  = [];
        $cursor = null;
        $pages  = 0;

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
                $createdAt = (string) ($node['createdAt'] ?? '');
                if ($createdAt === '') {
                    continue;
                }

                // Bucket by the brand's local date.
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

        // Assemble one snapshot per day.
        $todayLocal = CarbonImmutable::now($tz)->toDateString();
        $snapshots  = [];
        foreach ($byDay as $date => $totals) {
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
