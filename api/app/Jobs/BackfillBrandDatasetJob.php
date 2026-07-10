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
 * Runs one dataset backfill (campaigns / creatives / commerce) for one brand
 * by driving the same artisan commands the operator would run by hand — one
 * code path for CLI and UI. Every command upserts, so a rerun resumes rather
 * than duplicates.
 *
 * Timeout: Horizon supervisors cap jobs at 600s. A 12-month pull normally
 * fits; if a rate-limited platform pushes past the cap the run is marked
 * failed with a "click again to resume" message — already-pulled rows are
 * kept (idempotent upserts), so the retry continues where data stops.
 */
class BackfillBrandDatasetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;      // an operator retries via the button, never blind auto-retry
    public int $timeout = 590;  // just under the Horizon supervisor's 600s cap

    public function __construct(
        public readonly Brand $brand,
        public readonly string $dataset,
        public readonly int $runId,
    ) {
        // Ads datasets share the ads worker pool (and its rate-limit pacing);
        // commerce hits ShopifyQL so it rides the shopify pool.
        $this->onQueue($dataset === 'commerce' ? 'shopify-sync' : 'ads-sync');
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
        $connected = $this->brand->connections()->where('status', 'active')->pluck('platform')->all();
        $commands  = [];
        if ($this->dataset === 'campaigns') {
            // ads:backfill-campaigns handles meta|google|tiktok itself.
            $commands[] = ['ads:backfill-campaigns', ['brand' => (string) $this->brand->slug, '--since' => $since]];
        }
        if ($this->dataset === 'creatives') {
            if (in_array('meta', $connected, true)) {
                $commands[] = ['meta:backfill-creatives', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            }
            if (in_array('tiktok', $connected, true)) {
                $commands[] = ['tiktok:backfill-creatives', ['brand' => (string) $this->brand->slug, '--since' => $since]];
            }
        }
        if ($this->dataset === 'commerce') {
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
