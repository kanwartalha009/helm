<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Platforms\PlatformRegistry;
use App\Platforms\Support\PlatformRateLimitedException;
use App\Platforms\Support\SyncFailureClassifier;
use App\Platforms\Support\Throttle;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PHASE 1 of the daily sync: the headline number, and nothing else.
 *
 * Resolves the adapter via PlatformRegistry, calls fetchDay(), writes ONE row into daily_metrics
 * (revenue for Shopify, spend for the ad platforms — the two columns the dashboard shows) and one
 * row into sync_logs. Then it dispatches SyncBrandEnrichmentJob and gets out of the way.
 *
 * Everything else — campaigns, ad sets, creatives, breakdowns, product spend, funnel, sessions,
 * commerce, Klaviyo — used to run inline here, which is why "Sync now" felt slow: a worker was
 * held for a minute or more per brand doing work nobody was waiting on, so the last brand's
 * revenue landed minutes after the first. See SyncBrandEnrichmentJob for the FIFO ordering that
 * now guarantees every brand's headline number is written before any enrichment starts.
 *
 * Throws on failure so Horizon handles retry (3 attempts, exponential backoff). See spec §12.3.
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

    public function handle(PlatformRegistry $registry, FxService $fx): void
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

            // ══ THE HEADLINE NUMBER IS NOW DOWN. STOP HERE. ══
            //
            // Everything else this brand-day needs — campaigns, ad sets, creatives, breakdowns,
            // product spend, funnel, sessions, commerce, Klaviyo — is ENRICHMENT. It used to run
            // inline, right here, and that was the reason "Sync now" felt slow: each job held a
            // worker for a minute or more doing enrichment, so with 88 brands the LAST brand's
            // revenue and spend — the two columns Bosco actually looks at — landed several
            // minutes after the first. The dashboard was waiting on data nobody was looking at.
            //
            // So enrichment moves to its own job. The ordering that makes this work is FIFO:
            // every phase-1 job is enqueued UP FRONT (sync:daily / triggerAll dispatch them all),
            // and an enrichment job is only pushed when its phase-1 finishes — landing it at the
            // BACK of the queue, behind all the other brands' phase-1 jobs. So every brand's
            // revenue + spend is written before ANY enrichment starts. The dashboard fills first,
            // completely, and the slow work drains afterwards.
            //
            // Same queue on purpose: a separate queue would need a new Horizon supervisor, and
            // would break exactly the ordering guarantee we're relying on.
            SyncBrandEnrichmentJob::dispatch($this->brand, $this->platformConnection, $this->date);
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



    /** @return array<int, int> retry delays in seconds: 1m, 5m, 15m */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
