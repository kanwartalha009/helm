<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Models\CommerceDailyMetric;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\ShopifyFunnelDaily;
use App\Models\SyncLog;
use App\Platforms\PlatformRegistry;
use App\Platforms\Shopify\RevenueFetcher;
use App\Platforms\Support\PlatformRateLimitedException;
use App\Platforms\Support\SyncFailureClassifier;
use App\Platforms\Support\Throttle;
use App\Services\Currency\FxService;
use App\Services\Sync\CampaignSync;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Platform-agnostic sync of one day for one (brand × connection).
 *
 * Resolves the adapter via PlatformRegistry, calls fetchDay(), writes a row
 * into daily_metrics and a row into sync_logs. Throws on failure so Horizon
 * handles retry (3 attempts, exponential backoff). See spec §12.3.
 */
class SyncBrandDayJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Attempt budget. Real failures are capped by $maxExceptions (3, matching
     * the original 1m/5m/15m retry contract); $tries is higher because a
     * rate-limit release() also consumes an attempt, and a legitimately
     * throttled platform shouldn't be able to burn the whole failure budget.
     */
    public int $tries = 6;

    /** Max attempts that end in a thrown exception (actual failures). */
    public int $maxExceptions = 3;

    /** Hard timeout per attempt — matches horizon.php. */
    public int $timeout = 600;

    /**
     * Note the property name: $platformConnection, not $connection. The
     * Queueable trait already declares a `$connection` property (used to
     * select which queue connection — redis/sync — the job dispatches on),
     * and PHP rejects the trait composition if our property name collides.
     *
     * $logId is the id of a prewritten sync_logs row in `queued` state. The
     * controller / cron command writes this row before dispatch so the
     * Sync health page can show pending work without waiting for the
     * worker to pick the job up. When $logId is null (older callers, tests),
     * the job creates its own row as before — back-compat preserved.
     */
    public function __construct(
        public readonly Brand $brand,
        public readonly PlatformConnection $platformConnection,
        public readonly CarbonImmutable $date,
        public readonly ?int $logId = null,
    ) {
        // 'shopify-sync' queue tolerates higher concurrency than ads-sync.
        $this->onQueue($platformConnection->platform === 'shopify' ? 'shopify-sync' : 'ads-sync');
    }

    public function handle(PlatformRegistry $registry, FxService $fx, CampaignSync $campaignSync, RevenueFetcher $revenue): void
    {
        // Two paths in:
        //   - $logId set      → controller/command already wrote a `queued`
        //                       row. We transition it to `running` here.
        //   - $logId null     → legacy callers. Create the row inline.
        // Either way, $log holds the row we update on success/failure.
        if ($this->logId !== null) {
            /** @var SyncLog|null $log */
            $log = SyncLog::find($this->logId);
            if ($log === null) {
                // Row was pruned (cleanup cron) between dispatch and handle.
                // Create a fresh one so the run is still recorded.
                $log = SyncLog::create([
                    'brand_id'    => $this->brand->id,
                    'platform'    => $this->platformConnection->platform,
                    'target_date' => $this->date->toDateString(),
                    'status'      => 'running',
                    'started_at'  => now(),
                ]);
            } else {
                $log->update([
                    'status'     => 'running',
                    'started_at' => now(),
                ]);
            }
        } else {
            $log = SyncLog::create([
                'brand_id'    => $this->brand->id,
                'platform'    => $this->platformConnection->platform,
                'target_date' => $this->date->toDateString(),
                'status'      => 'running',
                'started_at'  => now(),
            ]);
        }

        // Long platform waits (Shopify cost refill, Meta cool-down, TikTok
        // 40100) release the job back to the queue instead of sleeping in the
        // worker — but only on a real queue connection; release() is a no-op
        // on the sync driver (tests, dispatch_sync), where inline sleeps stay.
        Throttle::deferToQueue($this->job !== null && $this->job->getConnectionName() !== 'sync');

        try {
            $adapter  = $registry->for($this->platformConnection->platform);
            $snapshot = $adapter->fetchDay($this->platformConnection, $this->date);

            // Snapshot the native->USD rate onto the row at write time so the
            // dashboard's USD toggle reads `revenue * fx_rate_to_usd` straight
            // from SQL (docs/10 currency). cachedToUsd is a DB-only lookup, so
            // sync never blocks on the FX provider. When the rate isn't cached
            // yet it returns null and we flag the row fx_pending — the native
            // figures still land now and BackfillFxRatesJob fills the USD rate
            // once FetchDailyCurrencyRatesJob has pulled it.
            $fxRate = $fx->cachedToUsd($snapshot->currency, $this->date);
            $row    = $snapshot->toRow($fxRate, fxPending: $fxRate === null);

            // Model::upsert() bypasses Eloquent's cast pipeline, so the
            // `array`/`jsonb` cast on `metadata` is NOT applied. Encode
            // it here or PDO throws "Array to string conversion" when
            // binding. Pass null through unchanged.
            $row['metadata'] = is_array($row['metadata'] ?? null)
                ? json_encode($row['metadata'])
                : ($row['metadata'] ?? null);

            DailyMetric::upsert(
                [$row],
                ['brand_id', 'platform', 'date'],
                $snapshot->updateableFields()
            );

            $log->update([
                'status'            => 'success',
                'completed_at'      => now(),
                'records_processed' => 1,
            ]);

            $this->platformConnection->update([
                'status'       => 'active',
                'last_sync_at' => now(),
                'last_error'   => null,
            ]);

            // Keep the ads audit current: pull this day's campaign-level rows for
            // Meta/Google right after the account-level metric lands. Best-effort
            // — syncDay guards the platform and swallows its own errors, so it can
            // never fail the day sync that has already succeeded above.
            $campaignSync->syncDay($this->platformConnection, $this->date);

            // Meta audience-segment spend (ASC new/engaged/existing/unknown) for
            // the dashboard's Audience view. Meta-only + best-effort (self-guards
            // and swallows its own errors), so it never affects the main sync.
            $campaignSync->syncMetaBreakdown($this->platformConnection, $this->date);

            // TikTok audience breakdowns (country/device/age×gender) for the ads
            // hub's TikTok Audience view. Also best-effort + self-guarded to tiktok.
            foreach (['country', 'device', 'age_gender'] as $tiktokAxis) {
                $campaignSync->syncTikTokBreakdown($this->platformConnection, $this->date, $tiktokAxis);
            }

            // Google device + geographic breakdowns for the ads hub's Google
            // Overview (device donut/detail + region map). Google-only + best-effort.
            foreach (['device', 'country'] as $googleAxis) {
                $campaignSync->syncGoogleBreakdown($this->platformConnection, $this->date, $googleAxis);
            }

            // Shopify web funnel (sessions → cart → checkout → purchase) by country
            // + landing path for the monthly report's §10/§11. Shopify-only +
            // best-effort — a funnel hiccup never fails the day's main sync.
            if ($this->platformConnection->platform === 'shopify') {
                $this->syncShopifyFunnel($revenue, $this->platformConnection, $this->date);

                // Shopify commerce by country / product / category into
                // commerce_daily_metrics for the monthly report's §1/§2/§7/§8 and
                // the overall-performance breakdowns. Without this the granular
                // tables only ever hold whatever shopify:backfill-commerce last
                // wrote, so months drift stale/€0; this keeps them fresh forward.
                // Best-effort + self-guarded, like the funnel above.
                $this->syncShopifyCommerce($revenue, $fx, $this->platformConnection, $this->date);
            }
        } catch (PlatformRateLimitedException $e) {
            // Not a failure — the platform asked us to wait. Hand the worker
            // slot to another brand and come back after the reported delay
            // (+ jitter so a burst of throttled jobs doesn't stampede back
            // in the same second). The log returns to `queued` so Sync health
            // shows it as pending, not broken.
            $log->update([
                'status'        => 'queued',
                'error_message' => $e->getMessage(),
            ]);
            $this->release($e->retryAfterSeconds + random_int(1, 15));

            return;
        } catch (Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
            ]);

            // Per agency policy: the connection is permanent. A failed sync
            // never disconnects Shopify — the next sync retries automatically
            // and the ShopifyClient's onUnauthorized callback handles token
            // rotation in-band. We just stamp `last_error` so the UI surfaces
            // a "Sync issue" warning and the user can see what went wrong.
            // SyncFailureClassifier is consulted only for the log level.
            Log::warning('sync.day.failed', [
                'brand_id'      => $this->brand->id,
                'platform'      => $this->platformConnection->platform,
                'date'          => $this->date->toDateString(),
                'auth_failure'  => SyncFailureClassifier::isAuthFailure($e),
                'message'       => $e->getMessage(),
            ]);
            $this->platformConnection->update([
                'status'     => 'active',
                'last_error' => $e->getMessage(),
            ]);
            report($e);
            throw $e;   // hand to Horizon for retry
        } finally {
            // One worker process handles one job at a time; always reset so a
            // console command running later in this process sleeps inline.
            Throttle::deferToQueue(false);
        }
    }

    /**
     * Terminal failure (max attempts / maxExceptions exhausted, or the worker
     * killed on timeout). The catch above already stamps `failed` on a normal
     * exception path, but a timeout or exhausted release loop bypasses it —
     * without this, the prewritten sync_logs row would sit `queued`/`running`
     * forever and Sync health would show phantom pending work.
     */
    public function failed(Throwable $e): void
    {
        if ($this->logId === null) {
            return;
        }

        SyncLog::query()
            ->where('id', $this->logId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
            ]);
    }

    /**
     * Pull one day of the Shopify web funnel (sessions → cart → checkout →
     * purchase) by country + landing path into shopify_funnel_daily. Best-effort:
     * each dimension guards itself so a ShopifyQL hiccup never touches the day's
     * main sync, which has already succeeded. History is filled by
     * shopify:backfill-funnel; this keeps it fresh.
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

    /** @return array<int, int> retry delays in seconds: 1m, 5m, 15m */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
