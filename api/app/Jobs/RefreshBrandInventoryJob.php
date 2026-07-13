<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BackfillRun;
use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * "Sync now" on Inventory Intelligence — refresh EXACTLY the four datasets that page reads, for a
 * short recent window, and report progress while it runs.
 *
 *   stock     → shopify:sync-catalog            (a snapshot; normally only the 14:10 UTC cron)
 *   sales     → shopify:backfill-commerce       (units + revenue by product)
 *   ad spend  → meta|ads:backfill-ad-products   (product-attributed spend → the ROAS denominator)
 *   sessions  → shopify:backfill-session-traffic (Bosco item B)
 *
 * ══ WHY --force ON SESSIONS ══
 * The session backfill is resume-aware: it skips days already in `backfill_coverage`. That is
 * exactly right for a history pull and exactly WRONG for a "give me fresh data" button — the
 * recent days are already marked done, so without --force the button would do nothing and report
 * success. `--force` is scoped to the refresh window only, so the year of history behind it stays
 * marked and is not re-pulled.
 *
 * The window is deliberately SHORT (default 7 days). This button exists to top up today's numbers,
 * not to re-pull history — session traffic costs ~5 ShopifyQL calls per day, so a wide window here
 * would turn a click into an hour.
 */
class RefreshBrandInventoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;      // the operator retries via the button; never blind auto-retry
    public int $timeout = 1800; // well under the 3600s supervisor cap

    /** The dataset key on backfill_runs — keeps this out of the coverage-card datasets. */
    public const DATASET = 'inventory-refresh';

    public function __construct(
        public readonly Brand $brand,
        public readonly int $runId,
        public readonly int $days = 7,
    ) {
        $this->onQueue('shopify-sync');
    }

    public function handle(): void
    {
        $run = BackfillRun::find($this->runId);
        if ($run === null || $run->status !== 'queued') {
            return; // cancelled, or a duplicate click already handled it
        }

        $run->update(['status' => 'running', 'started_at' => now(), 'message' => 'Starting…']);

        $slug      = (string) $this->brand->slug;
        $tz        = $this->brand->timezone ?: 'UTC';
        $since     = CarbonImmutable::now($tz)->subDays(max(1, $this->days))->toDateString();
        $connected = $this->brand->connections()->where('status', 'active')->pluck('platform')->all();

        // Ordered cheapest → most expensive, and each step names itself in `message` so the UI can
        // say WHICH dataset is syncing rather than showing an anonymous spinner.
        $steps = [];

        if (in_array('shopify', $connected, true)) {
            $steps[] = ['Stock', 'shopify:sync-catalog', ['brand' => $slug]];
            $steps[] = ['Sales', 'shopify:backfill-commerce', ['brand' => $slug, '--since' => $since]];
        }
        if (in_array('meta', $connected, true)) {
            $steps[] = ['Meta product spend', 'meta:backfill-ad-products', ['brand' => $slug, '--since' => $since]];
        }
        if (array_intersect(['google', 'tiktok'], $connected) !== []) {
            $steps[] = ['Google/TikTok product spend', 'ads:backfill-ad-products', ['brand' => $slug, '--since' => $since]];
        }
        if (in_array('shopify', $connected, true)) {
            // --force: these days are already marked covered, and a refresh that skips them would
            // report success having done nothing.
            $steps[] = ['Sessions', 'shopify:backfill-session-traffic', ['brand' => $slug, '--since' => $since, '--force' => true]];
        }

        if ($steps === []) {
            $run->update([
                'status'      => 'done',
                'finished_at' => now(),
                'message'     => 'Nothing to sync — this brand has no connected platforms.',
            ]);

            return;
        }

        $total = count($steps);
        $tail  = [];

        try {
            foreach ($steps as $i => [$label, $command, $args]) {
                // Written BEFORE the step runs, so the UI shows what is happening NOW, not what
                // just finished. The step number is the progress bar.
                $run->update(['message' => sprintf('%d/%d · %s', $i + 1, $total, $label)]);

                Artisan::call($command, $args);
                $out    = trim(Artisan::output());
                $tail[] = "{$label}: " . mb_substr($out !== '' ? $out : 'ok', -200);
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

    /** Worker killed on timeout — the catch above never ran, so the row would hang on 'running'. */
    public function failed(Throwable $e): void
    {
        BackfillRun::query()
            ->where('id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'message'     => mb_substr('Sync failed: ' . $e->getMessage(), 0, 2000),
            ]);
    }
}
