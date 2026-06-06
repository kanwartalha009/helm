<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Platforms\Shopify\ShopifyClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only reconciliation. Pulls one day of Shopify orders for one brand and
 * breaks them down by source_name, cancelled, and test, then prints the stored
 * daily_metrics row alongside — so we can see exactly why Helm's "Total sales
 * before returns" differs from a Shopify report (channel vs cancelled/test vs
 * timezone) instead of guessing.
 *
 *   php artisan shopify:diagnose flabelus 2026-06-05
 */
class ShopifyChannelDiagnoseCommand extends Command
{
    protected $signature = 'shopify:diagnose {brand : brand slug or id} {date : YYYY-MM-DD in the brand timezone}';
    protected $description = 'Break down a brand\'s Shopify orders for a day by source_name / cancelled / test and compare to the stored daily_metrics row.';

    public function handle(): int
    {
        $brandArg = (string) $this->argument('brand');
        $lower    = strtolower(trim($brandArg));

        // Match on id, or exact slug/name (case-insensitive), or a partial
        // name/slug — so "flabelus" finds the brand "Flabelus" without needing
        // the exact generated slug.
        $brand = is_numeric($brandArg)
            ? Brand::find((int) $brandArg)
            : (Brand::query()
                ->whereRaw('LOWER(slug) = ?', [$lower])
                ->orWhereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?: Brand::query()
                    ->where('name', 'like', '%' . $brandArg . '%')
                    ->orWhere('slug', 'like', '%' . $brandArg . '%')
                    ->first());

        if (! $brand) {
            $this->error("Brand not found: {$brandArg}");
            $hints = Brand::query()
                ->where('name', 'like', '%' . $brandArg . '%')
                ->orWhere('slug', 'like', '%' . $brandArg . '%')
                ->limit(10)
                ->get(['name', 'slug']);
            if ($hints->isNotEmpty()) {
                $this->line('Closest matches:');
                foreach ($hints as $h) {
                    $this->line("  - {$h->name}  (slug: {$h->slug})");
                }
            } else {
                $this->line('Pass the brand id or exact slug (see the Brands page for the slug).');
            }

            return self::FAILURE;
        }

        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->first();

        if (! $conn || ($token = (string) ($conn->credentials['access_token'] ?? '')) === '') {
            $this->error("No usable Shopify connection for {$brand->name}.");
            return self::FAILURE;
        }

        $tz = $brand->timezone ?: 'UTC';
        try {
            $day = CarbonImmutable::parse((string) $this->argument('date'), $tz)->startOfDay();
        } catch (Throwable $e) {
            $this->error('Invalid date: ' . $e->getMessage());
            return self::INVALID;
        }

        $startUtc = $day->setTimezone('UTC')->toIso8601String();
        $endUtc   = $day->endOfDay()->setTimezone('UTC')->toIso8601String();
        $client   = new ShopifyClient((string) $conn->external_id, $token);

        $gql = <<<'GQL'
query($q: String!, $first: Int!, $after: String) {
  orders(first: $first, query: $q, sortKey: CREATED_AT, after: $after) {
    edges {
      node {
        id
        sourceName
        test
        cancelledAt
        taxesIncluded
        subtotalPriceSet { shopMoney { amount } }
        currentSubtotalPriceSet { shopMoney { amount } }
        currentTotalTaxSet { shopMoney { amount } }
        totalPriceSet { shopMoney { amount } }
        totalRefundedSet { shopMoney { amount } }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

        $q = "status:any AND created_at:>='{$startUtc}' AND created_at:<='{$endUtc}'";

        $bySource         = [];
        $totalCount       = 0;
        $totalRevenue     = 0.0;
        $cancelledCount   = 0;
        $cancelledRevenue = 0.0;
        $testCount        = 0;
        $webLiveCount     = 0;
        $webLiveRevenue   = 0.0;
        $netSubtotal      = 0.0;  // Σ subtotalPriceSet (after discounts, before returns)
        $netCurrentSub    = 0.0;  // Σ currentSubtotalPriceSet (after discounts AND returns)
        $netCurrentExTax  = 0.0;  // Σ (currentSubtotal − currentTax) — ex-tax, after returns
        $taxesIncluded    = null;
        $cursor           = null;
        $pages            = 0;

        do {
            $data  = $client->graphql($gql, ['q' => $q, 'first' => 250, 'after' => $cursor]);
            $edges = $data['orders']['edges'] ?? [];

            foreach ($edges as $edge) {
                $n   = $edge['node'] ?? [];
                $src = strtolower((string) ($n['sourceName'] ?? '')) ?: 'unknown';
                $rev = (float) ($n['totalPriceSet']['shopMoney']['amount'] ?? 0);
                $isCancelled = ! empty($n['cancelledAt']);
                $isTest      = (bool) ($n['test'] ?? false);

                $totalCount++;
                $totalRevenue += $rev;
                $bySource[$src]['count']   = ($bySource[$src]['count'] ?? 0) + 1;
                $bySource[$src]['revenue'] = ($bySource[$src]['revenue'] ?? 0) + $rev;

                if ($isCancelled) {
                    $cancelledCount++;
                    $cancelledRevenue += $rev;
                }
                if ($isTest) {
                    $testCount++;
                }
                if ($src === 'web' && ! $isCancelled && ! $isTest) {
                    $webLiveCount++;
                    $webLiveRevenue += $rev;

                    $cs = (float) ($n['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0);
                    $ct = (float) ($n['currentTotalTaxSet']['shopMoney']['amount'] ?? 0);
                    $netSubtotal     += (float) ($n['subtotalPriceSet']['shopMoney']['amount'] ?? 0);
                    $netCurrentSub   += $cs;
                    $netCurrentExTax += ($cs - $ct);
                    if ($taxesIncluded === null) {
                        $taxesIncluded = (bool) ($n['taxesIncluded'] ?? false);
                    }
                }
            }

            $pi      = $data['orders']['pageInfo'] ?? [];
            $cursor  = (string) ($pi['endCursor'] ?? '');
            $hasNext = (bool) ($pi['hasNextPage'] ?? false);
            $pages++;
        } while ($hasNext && $cursor !== '' && $pages < 50);

        $this->info("Shopify orders for {$brand->name} on {$day->toDateString()} ({$tz})");
        $this->newLine();

        $rows = [];
        foreach ($bySource as $src => $v) {
            $rows[] = [$src, $v['count'], number_format((float) $v['revenue'], 2)];
        }
        $this->table(['source_name', 'orders', 'revenue (totalPrice, before returns)'], $rows);

        $this->line('All channels:        ' . $totalCount . ' orders · ' . number_format($totalRevenue, 2));
        $this->line('Cancelled:           ' . $cancelledCount . ' orders · ' . number_format($cancelledRevenue, 2));
        $this->line('Test orders:         ' . $testCount);
        $this->line('Online Store (web, not cancelled, not test): ' . $webLiveCount . ' orders · ' . number_format($webLiveRevenue, 2));
        $this->newLine();

        $this->line('Net sales candidates (web, not cancelled, not test) — match against Shopify "Net sales":');
        $this->line('  taxesIncluded:                ' . ($taxesIncluded ? 'true (prices include VAT)' : 'false'));
        $this->line('  subtotalPriceSet:             ' . number_format($netSubtotal, 2) . '   (after discounts, before returns)');
        $this->line('  currentSubtotalPriceSet:      ' . number_format($netCurrentSub, 2) . '   (after discounts AND returns)');
        $this->line('  currentSubtotal - currentTax: ' . number_format($netCurrentExTax, 2) . '   (ex-tax, after returns)');
        $this->newLine();

        $stored = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('date', $day->toDateString())
            ->first();

        if ($stored) {
            $this->line(
                'Helm stored row:     ' . (int) $stored->orders . ' orders · revenue '
                . number_format((float) $stored->revenue, 2) . ' · refunds '
                . number_format((float) ($stored->refunds_amount ?? 0), 2) . ' · ' . $stored->currency
            );
        } else {
            $this->warn('No Helm daily_metrics row stored for this brand/day yet.');
        }

        return self::SUCCESS;
    }
}
