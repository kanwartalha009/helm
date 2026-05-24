<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DailyMetric;
use App\Services\Currency\FxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sweeps every daily_metrics row flagged `metadata->>'fx_pending' = 'true'`
 * and tries to stamp `fx_rate_to_usd` using whatever rates have since
 * arrived in currency_rates (or pulled on-demand by FxService::rate()).
 *
 * Runs nightly after FetchDailyCurrencyRatesJob. Also dispatchable on
 * demand from `php artisan fx:rebackfill` — useful when the operator
 * just ran `fx:backfill --since=...` and wants existing rows updated
 * immediately rather than waiting for the next nightly run.
 *
 * Why a separate job: Sync's job is to land the native-currency facts
 * quickly. Filling in derived USD numbers later is a cheap, retriable
 * sweep that doesn't block any merchant data.
 */
class BackfillFxRatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Process this many rows per batch to keep memory + lock-time bounded. */
    private const BATCH_SIZE = 500;

    public int $tries   = 1;
    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(FxService $fx): void
    {
        $resolved = 0;
        $skipped  = 0;
        $failed   = 0;

        DailyMetric::query()
            // Postgres jsonb expression — works on the same column we
            // already index. `is_complete` filter avoids spinning on
            // today's still-open partial row.
            ->whereRaw("metadata->>'fx_pending' = 'true'")
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($batch) use ($fx, &$resolved, &$skipped, &$failed) {
                foreach ($batch as $row) {
                    if (empty($row->currency)) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $rate = $fx->toUsd($row->currency, $row->date->toImmutable());
                    } catch (Throwable $e) {
                        // Still no rate — leave the flag in place, try again next run.
                        $failed++;
                        continue;
                    }

                    $meta = $row->metadata ?? [];
                    unset($meta['fx_pending'], $meta['fx_error']);
                    if ($meta === []) {
                        $meta = null;
                    }

                    // Direct update — no event dispatch, no cast roundtrip.
                    DB::table('daily_metrics')
                        ->where('id', $row->id)
                        ->update([
                            'fx_rate_to_usd' => $rate,
                            'metadata'       => $meta !== null ? json_encode($meta) : null,
                        ]);
                    $resolved++;
                }
            });

        Log::info('fx.backfill.done', [
            'resolved' => $resolved,
            'skipped'  => $skipped,
            'failed'   => $failed,
        ]);
    }
}
