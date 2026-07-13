<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\ShopifyFunnelDaily;
use App\Platforms\Shopify\RevenueFetcher;
use App\Services\Currency\FxService;
use App\Services\Sync\AdSetSync;
use App\Services\Sync\CampaignSync;
use App\Services\Sync\CreativeSync;
use App\Services\Sync\KlaviyoSync;
use App\Services\Sync\SessionTrafficSync;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 2 of the daily sync: everything that is NOT the headline number.
 *
 * ══ WHY THIS IS A SEPARATE JOB ══
 * SyncBrandDayJob used to do all of this inline, right after writing daily_metrics. That made
 * "Sync now" feel broken: each job held a worker for a minute or more doing campaigns, creatives,
 * breakdowns and sessions — so across 88 brands, the LAST brand's revenue and spend (the two
 * columns the dashboard actually shows) landed minutes after the first. Bosco was staring at a
 * half-empty dashboard while the queue ground through data nobody was looking at yet.
 *
 * Splitting it exploits FIFO ordering, and the ordering is the whole trick:
 *
 *   1. sync:daily / "Sync now" enqueue a phase-1 job for EVERY brand-connection, up front.
 *   2. Each phase-1 job writes daily_metrics and finishes FAST, then dispatches its enrichment
 *      job — which lands at the BACK of the queue, behind every other brand's phase-1 job.
 *   3. So every brand's revenue + spend is written before ANY enrichment begins. The dashboard
 *      fills completely, then the slow work drains.
 *
 * It rides the SAME queue on purpose. A dedicated queue would need a new Horizon supervisor AND
 * would destroy the ordering guarantee above — two pools drain in parallel, so enrichment for
 * brand 1 would compete with phase-1 for brand 88, which is exactly what we're fixing.
 *
 * Every step here is best-effort and self-guarding: the day's headline number has ALREADY been
 * written and its sync_log already says success, so nothing in this job may fail that.
 */
class SyncBrandEnrichmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Enrichment is best-effort: one retry, then let it go. The headline number is safe either way. */
    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public readonly Brand $brand,
        public readonly PlatformConnection $platformConnection,
        public readonly CarbonImmutable $date,
    ) {
        // Same queue as phase 1 — see the class docblock. This is load-bearing, not incidental.
        $this->onQueue($platformConnection->platform === 'shopify' ? 'shopify-sync' : 'ads-sync');
    }

    public function handle(
        FxService $fx,
        CampaignSync $campaignSync,
        AdSetSync $adSetSync,
        CreativeSync $creativeSync,
        RevenueFetcher $revenue,
        KlaviyoSync $klaviyoSync,
        SessionTrafficSync $sessionTraffic,
    ): void {
        $conn = $this->platformConnection;
        $date = $this->date;

        try {
            // Campaign-level rows for the ads audit.
            $campaignSync->syncDay($conn, $date);

            // Ad-set / ad-group / asset-group grain (spec §4 Phase 3c) — the under-performer drilldown.
            $adSetSync->syncRange($conn, $date, $date);

            // Ad-level (creative) rows — Meta + TikTok. thumbnail_url is a short-lived CDN link,
            // so every sync refreshes it; creatives:refresh-thumbnails tops up the older days.
            $creativeSync->syncRange($conn, $date, $date);

            // Meta audience-segment spend (ASC new/engaged/existing/unknown) — dashboard Audience view.
            $campaignSync->syncMetaBreakdown($conn, $date);

            // Meta spend attributed to Shopify products (ad_product_daily) — Inventory ROAS.
            $campaignSync->syncMetaAdProducts($conn, $date);

            // TikTok audience breakdowns (country/device/age×gender).
            foreach (['country', 'device', 'age_gender'] as $tiktokAxis) {
                $campaignSync->syncTikTokBreakdown($conn, $date, $tiktokAxis);
            }

            // Google device + geographic breakdowns.
            foreach (['device', 'country'] as $googleAxis) {
                $campaignSync->syncGoogleBreakdown($conn, $date, $googleAxis);
            }

            if ($conn->platform === 'shopify') {
                // GROSS revenue — the order-by-order scan, moved OFF the dashboard's critical
                // path. It took 74s on Meller (~3,500 orders/day) while every other sync ran in
                // 1-2s, and it produced nothing the dashboard's headline number needs. Phase 1
                // now writes total_sales/net_sales/orders/refunds from one ShopifyQL call; this
                // fills in the three columns only the scan can produce.
                $this->syncGrossRevenue($revenue, $conn, $date);

                // Web funnel (sessions → cart → checkout → purchase) — monthly report §10/§11.
                $this->syncShopifyFunnel($revenue, $conn, $date);

                // Sessions by traffic type per landing entity (Bosco item B) — Inventory.
                // Self-reconciling: a day whose paged rows don't add up to Shopify's own store
                // total is stored is_complete = false and renders "—", never a short number.
                $sessionTraffic->syncDay($conn, $date->toDateString());

                // Commerce by country / product / category — monthly report §1/§2/§7/§8.
                $this->syncShopifyCommerce($revenue, $fx, $conn, $date);

                // Klaviyo email revenue. On the SHOPIFY connection so it fires once per brand-day,
                // not once per ad connection. Its own channel — never added to store or ad revenue.
                $klaviyoSync->syncBrandDaySafe($this->brand, $date);
            }
        } catch (Throwable $e) {
            // The headline number is already down and its sync_log already reads success. An
            // enrichment failure must never rewrite that into a failure — it would tell the
            // operator the day is broken when the number they're looking at is correct.
            Log::warning('sync.enrichment.failed', [
                'brand_id' => $this->brand->id,
                'platform' => $conn->platform,
                'date'     => $date->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            throw $e;   // let Horizon retry once; the failure is visible in Horizon, not in Sync health
        }
    }

    /**
     * Gross revenue for the day, from the order-by-order scan — the 74-second call, off the
     * dashboard's critical path.
     *
     * Writes ONLY the three columns the scan uniquely produces. total_sales / net_sales / orders /
     * refunds_amount belong to phase 1 (one ShopifyQL call, authoritative and fast); letting a
     * slow, fallible scan overwrite them would risk replacing a correct number the operator is
     * already looking at with a worse one.
     */
    private function syncGrossRevenue(RevenueFetcher $revenue, PlatformConnection $conn, CarbonImmutable $date): void
    {
        try {
            $gross = $revenue->fetchGrossDay($conn, $date);
        } catch (Throwable $e) {
            Log::warning('sync.gross_revenue.failed', [
                'brand_id' => $conn->brand_id,
                'date'     => $date->toDateString(),
                'error'    => $e->getMessage(),
            ]);

            return;
        }

        // An incomplete scan writes NOTHING. A page-capped partial total would understate a big
        // brand's gross revenue while looking perfectly precise.
        if ($gross === null) {
            return;
        }

        // ONLY the three columns the scan uniquely produces. total_sales / net_sales / orders /
        // refunds_amount are phase 1's, written from ShopifyQL — a slow, fallible scan must never
        // overwrite the fast, authoritative figures the dashboard is already showing.
        DailyMetric::query()
            ->where('brand_id', $conn->brand_id)
            ->where('platform', 'shopify')
            ->where('date', $date->toDateString())
            ->update([
                'revenue'         => $gross['revenue'],
                'revenue_net'     => $gross['revenueNet'],
                'refunded_orders' => $gross['refundedOrders'],
            ]);
    }

    /**
     * One day of the Shopify web funnel (sessions → cart → checkout → purchase) by country +
     * landing path into shopify_funnel_daily. Each dimension guards itself, so a ShopifyQL hiccup
     * never touches the headline number, which is already written. History: shopify:backfill-funnel.
     */
    private function syncShopifyFunnel(RevenueFetcher $revenue, PlatformConnection $conn, CarbonImmutable $date): void
    {
        $day = $date->toDateString();

        foreach (['country' => 'session_country', 'landing' => 'landing_page_path'] as $type => $dim) {
            try {
                $rows = $revenue->funnelByDimensionRange($conn, $dim, $day, $day);
            } catch (Throwable $e) {
                Log::warning('sync.shopify_funnel.failed', [
                    'brand_id'  => $conn->brand_id,
                    'dimension' => $type,
                    'date'      => $day,
                    'error'     => $e->getMessage(),
                ]);
                continue;
            }
            if ($rows === []) {
                continue;
            }

            $records = [];
            foreach ($rows as $r) {
                $seg = trim((string) ($r['segment_key'] ?? ''));
                if ($seg === '') {
                    continue;
                }
                $records[] = [
                    'brand_id'           => (int) $conn->brand_id,
                    'date'               => $day,
                    'dimension'          => $type,
                    'segment_key'        => mb_substr($seg, 0, 191),
                    'segment_label'      => mb_substr((string) ($r['segment_label'] ?? $seg), 0, 191),
                    'sessions'           => (int) ($r['sessions'] ?? 0),
                    'cart_additions'     => (int) ($r['cart_additions'] ?? 0),
                    'reached_checkout'   => (int) ($r['reached_checkout'] ?? 0),
                    'completed_checkout' => (int) ($r['completed_checkout'] ?? 0),
                    'is_complete'        => true,
                    'pulled_at'          => now(),
                ];
            }

            foreach (array_chunk($records, 500) as $chunk) {
                ShopifyFunnelDaily::upsert(
                    $chunk,
                    ['brand_id', 'date', 'dimension', 'segment_key'],
                    ['segment_label', 'sessions', 'cart_additions', 'reached_checkout', 'completed_checkout', 'is_complete', 'pulled_at'],
                );
            }
        }
    }

    /**
     * Pull one day of Shopify commerce (revenue / orders / units / refunds) split
     * by country, product and category into commerce_daily_metrics — the granular
     * tables behind the monthly report's §1/§2/§7/§8 and the overall-performance
     * breakdowns. Native revenue + the day's stored fx snapshot (spec rule 7), so
     * reports show USD without converting at read time. Best-effort: each
     * dimension self-guards so a ShopifyQL hiccup never touches the day's main
     * sync (already succeeded). History is filled by shopify:backfill-commerce;
     * this keeps it fresh going forward. Upsert key + update list match the
     * backfill exactly, so the two paths are idempotent against each other.
     */
    private function syncShopifyCommerce(RevenueFetcher $revenue, FxService $fx, PlatformConnection $conn, CarbonImmutable $date): void
    {
        $day      = $date->toDateString();
        $currency = (string) ($this->brand->base_currency ?: 'USD');
        $fxRate   = $fx->cachedToUsd($currency, $date);

        foreach (['country' => 'billing_country', 'product' => 'product_title', 'category' => 'product_type'] as $type => $dim) {
            try {
                $sales = $revenue->salesByDimensionRange($conn, $dim, $day, $day);
            } catch (Throwable $e) {
                Log::warning('sync.shopify_commerce.failed', [
                    'brand_id'  => $conn->brand_id,
                    'dimension' => $type,
                    'date'      => $day,
                    'error'     => $e->getMessage(),
                ]);
                continue;
            }
            if ($sales === []) {
                continue;
            }

            $records = [];
            foreach ($sales as $r) {
                $key = trim((string) ($r['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $records[] = [
                    'brand_id'        => (int) $conn->brand_id,
                    'date'            => $day,
                    'dimension_type'  => $type,
                    'dimension_key'   => mb_substr($key, 0, 191),
                    'dimension_label' => mb_substr((string) ($r['label'] ?? $key), 0, 191),
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

            foreach (array_chunk($records, 500) as $chunk) {
                CommerceDailyMetric::upsert(
                    $chunk,
                    ['brand_id', 'date', 'dimension_type', 'dimension_key'],
                    ['dimension_label', 'orders', 'units', 'net_sales', 'total_sales', 'refunds_amount', 'currency', 'fx_rate_to_usd', 'is_complete', 'pulled_at'],
                );
            }
        }
    }
}
