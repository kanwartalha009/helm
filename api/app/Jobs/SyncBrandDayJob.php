<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Platforms\PlatformRegistry;
use App\Platforms\Support\SyncFailureClassifier;
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

    /** Max attempts including the first one. Horizon retries with 1m / 5m / 15m backoff. */
    public int $tries = 3;

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

    public function handle(PlatformRegistry $registry, FxService $fx, CampaignSync $campaignSync): void
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
        }
    }

    /** @return array<int, int> retry delays in seconds: 1m, 5m, 15m */
    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
