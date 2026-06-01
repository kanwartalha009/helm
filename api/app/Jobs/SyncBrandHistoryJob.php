<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Platforms\PlatformRegistry;
use App\Platforms\Shopify\RevenueFetcher;
use App\Platforms\Shopify\ShopifyAdapter;
use App\Platforms\Support\SyncFailureClassifier;
use App\Services\Currency\FxService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One-shot full-history sync for one (brand × Shopify connection).
 *
 * Triggered by the manual "Sync now" button so a freshly installed store sees
 * real data on the dashboard immediately — every order Shopify will hand back,
 * grouped per-day in the brand's timezone. The nightly cron stays on its
 * 7-day rolling window and is the source of truth for late refund attribution.
 *
 * Only Shopify uses this path today — ad platforms still go through the
 * per-day SyncBrandDayJob once their adapters are implemented.
 */
class SyncBrandHistoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A full-history scan can run hot — give it 15 minutes per attempt. */
    public int $timeout = 900;

    /** Two attempts. If a paginated scan dies mid-stream the retry restarts cleanly. */
    public int $tries = 2;

    /**
     * Property name is $platformConnection, not $connection — the Queueable
     * trait already claims `$connection` for queue-connection routing.
     *
     * $logId is the id of a prewritten `queued` sync_logs row. Same
     * mechanism as SyncBrandDayJob — see that file for the rationale.
     */
    public function __construct(
        public readonly Brand $brand,
        public readonly PlatformConnection $platformConnection,
        public readonly ?CarbonImmutable $since = null,
        public readonly ?int $logId = null,
    ) {
        $this->onQueue('shopify-sync');
    }

    public function handle(PlatformRegistry $registry, RevenueFetcher $fetcher, FxService $fx): void
    {
        if ($this->platformConnection->platform !== 'shopify') {
            // History fan-out for ad platforms ships in Phase 2.
            return;
        }

        $adapter = $registry->for('shopify');
        if (! $adapter instanceof ShopifyAdapter) {
            throw new \RuntimeException('Shopify adapter missing from registry.');
        }

        // Same two-path pattern as SyncBrandDayJob — either transition a
        // prewritten `queued` row or create one inline for legacy callers.
        if ($this->logId !== null) {
            /** @var SyncLog|null $log */
            $log = SyncLog::find($this->logId);
            if ($log === null) {
                $log = SyncLog::create([
                    'brand_id'    => $this->brand->id,
                    'platform'    => 'shopify',
                    'target_date' => CarbonImmutable::now($this->brand->timezone)->toDateString(),
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
                'platform'    => 'shopify',
                // We log the target_date as today — the row spans many days
                // but the schema has a NOT NULL date column. Metadata
                // captures the range.
                'target_date' => CarbonImmutable::now($this->brand->timezone)->toDateString(),
                'status'      => 'running',
                'started_at'  => now(),
            ]);
        }

        try {
            $snapshots = $fetcher->fetchAllSince($this->platformConnection, $this->since);

            if ($snapshots === []) {
                // Shopify returned nothing — empty store, or filter excluded everything.
                $log->update([
                    'status'            => 'success',
                    'completed_at'      => now(),
                    'records_processed' => 0,
                    'error_message'     => 'No orders returned by Shopify in this window.',
                ]);
                $this->platformConnection->update([
                    'status'       => 'active',
                    'last_sync_at' => now(),
                    'last_error'   => null,
                ]);
                return;
            }

            // Snapshot the native->USD rate per day at write time (docs/10
            // currency). Each historical day gets its own rate; cachedToUsd is
            // a DB-only lookup, so a months-long backfill never hammers the FX
            // provider inside the sync loop. Run `php artisan fx:backfill
            // --since=<first order date>` first to populate currency_rates;
            // any day still missing a rate lands fx_pending and is filled later
            // by BackfillFxRatesJob.
            $rows = [];
            foreach ($snapshots as $snapshot) {
                $fxRate = $fx->cachedToUsd($snapshot->currency, $snapshot->date);
                $rows[] = $snapshot->toRow($fxRate, fxPending: $fxRate === null);
            }

            // Use the first snapshot's updateableFields() — they're identical
            // across snapshots since they come from the same DTO class.
            $updateable = reset($snapshots)->updateableFields();

            // Model::upsert() bypasses Eloquent's cast pipeline, so the
            // `array` cast on `metadata` (jsonb) is NOT applied. Encode
            // it here or PDO throws "Array to string conversion" when
            // binding. Pass null through unchanged.
            foreach ($rows as $i => $r) {
                if (isset($r['metadata']) && is_array($r['metadata'])) {
                    $rows[$i]['metadata'] = json_encode($r['metadata']);
                }
            }

            DailyMetric::upsert(
                $rows,
                ['brand_id', 'platform', 'date'],
                $updateable
            );

            $firstDay = array_key_first($snapshots);
            $lastDay  = array_key_last($snapshots);

            $log->update([
                'status'            => 'success',
                'completed_at'      => now(),
                'records_processed' => count($rows),
                'error_message'     => "Backfilled {$firstDay} → {$lastDay} ({$lastDay} is partial).",
            ]);

            $this->platformConnection->update([
                'status'       => 'active',
                'last_sync_at' => now(),
                'last_error'   => null,
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
            ]);

            // Per agency policy: the connection is permanent. A failed sync
            // never disconnects Shopify — the next sync retries automatically
            // and ShopifyClient::onUnauthorized handles token rotation
            // in-band. We just stamp `last_error` so the UI shows the
            // "Sync issue" warning. SyncFailureClassifier is consulted
            // only for the log level.
            \Illuminate\Support\Facades\Log::warning('sync.history.failed', [
                'brand_id'     => $this->brand->id,
                'auth_failure' => SyncFailureClassifier::isAuthFailure($e),
                'message'      => $e->getMessage(),
            ]);
            $this->platformConnection->update([
                'status'     => 'active',
                'last_error' => $e->getMessage(),
            ]);
            report($e);
            throw $e;
        }
    }

    public function backoff(): array
    {
        return [60, 300];
    }
}
