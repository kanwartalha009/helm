<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BackfillRun;
use App\Models\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * ONE queued job per backfill click — 'all' covers every dataset and every
 * connected platform for the brand (2026-07-10); 'history'/'campaigns'/
 * 'creatives'/'commerce' run subsets. Drives the same RANGED artisan commands
 * an operator would run by hand (weekly/monthly API chunks internally — never
 * a per-day fan-out), so CLI and UI share one code path. Every command
 * upserts: a rerun resumes rather than duplicates.
 *
 * Timeout: supervisors allow 3600s (horizon.php) and redis retry_after is
 * 3700s (config/queue.php) — this job stops at 3500s. If a heavily
 * rate-limited pull still hits the cap, the run is marked failed with a
 * "click again to resume" message; already-pulled rows are kept.
 */
class BackfillBrandDatasetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;      // an operator retries via the button, never blind auto-retry
    public int $timeout = 3500; // under the 3600s supervisor cap (horizon.php) and the 3700s retry_after (config/queue.php)

    public function __construct(
        public readonly Brand $brand,
        public readonly string $dataset,
        public readonly int $runId,
    ) {
        // 'all'/'history' mix Shopify + ads commands → the light default pool,
        // so a big onboarding pull never starves the daily sync pools. Pure ads
        // datasets ride ads-sync; commerce rides shopify-sync.
        $this->onQueue(match ($dataset) {
            'commerce'         => 'shopify-sync',
            'campaigns', 'creatives' => 'ads-sync',
            default            => 'default', // history | all
        });
    }

    public function handle(): void
    {
        $run = BackfillRun::find($this->runId);
        if ($run === null || $run->status !== 'queued') {
            return; // cancelled or already handled
        }

        $run->update(['status' => 'running', 'started_at' => now()]);
        $since = $run->window_start->toDateString();

        // Which commands serve which dataset, gated on connected platforms.
        // Every command here is RANGED (weekly/monthly API chunks internally) —
        // one queued job per click, never a per-day fan-out (2026-07-10).
        $connected = $this->brand->connections()->where('status', 'active')->pluck('platform')->all();
        $commands  = [];
        $wants     = fn (string $set): bool => $this->dataset === $set || $this->dataset === 'all';
        if ($wants('history')) {
            if (in_array('shopify', $connected, true)) {
                $commands[] = ['shopify:backfill-sales', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            }
            if (array_intersect(['meta', 'google'], $connected) !== []) {
                $commands[] = ['ads:backfill-spend', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            }
            if (in_array('tiktok', $connected, true)) {
                $commands[] = ['tiktok:backfill-daily', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            }
        }
        if ($wants('campaigns') && array_intersect(['meta', 'google', 'tiktok'], $connected) !== []) {
            // ads:backfill-campaigns handles meta|google|tiktok itself; the ad-set
            // grain (spec §4) rides the same dataset so one click fills both.
            $commands[] = ['ads:backfill-campaigns', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            $commands[] = ['ads:backfill-adsets', ['brand' => (string) $this->brand->slug, '--since' => $since]];
        }
        if ($wants('creatives')) {
            if (in_array('meta', $connected, true)) {
                $commands[] = ['meta:backfill-creatives', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            }
            if (in_array('tiktok', $connected, true)) {
                $commands[] = ['tiktok:backfill-creatives', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            }
        }
        if ($wants('commerce') && in_array('shopify', $connected, true)) {
            $commands[] = ['shopify:backfill-commerce', ['brand' => (string) $this->brand->slug, '--since' => $since]];
        }

        try {
            $tail = [];
            foreach ($commands as [$command, $args]) {
                Artisan::call($command, $args);
                $out    = trim(Artisan::output());
                $tail[] = "{$command}: " . mb_substr($out !== '' ? $out : 'ok', -400);
            }

            $run->update([
                'status'      => 'done',
                'finished_at' => now(),
                'message'     => mb_substr(implode("\n", $tail), 0, 2000),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'message'     => mb_substr($e->getMessage(), 0, 2000),
            ]);
            report($e);
        }
    }

    /** Worker killed on timeout — the catch above never ran. */
    public function failed(Throwable $e): void
    {
        BackfillRun::query()
            ->where('id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'message'     => 'Backfill hit the worker time limit — data pulled so far is kept. '
                    . 'Click Backfill again to resume from where it stopped. (' . mb_substr($e->getMessage(), 0, 300) . ')',
            ]);
    }
}
