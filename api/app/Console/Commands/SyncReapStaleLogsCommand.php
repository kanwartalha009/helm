<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;

/**
 * Reap ORPHANED sync_logs — rows stuck in `queued`/`running` whose job no longer exists.
 *
 * ══ HOW A ROW IS ORPHANED ══
 * A sync_logs row is written BEFORE the job is dispatched, so Sync health can show pending work
 * immediately. If the job then vanishes from Redis — the queue was flushed, Horizon was restarted
 * mid-flight, the worker was OOM-killed — nothing ever updates that row. It sits at `queued`
 * forever.
 *
 * That is not cosmetic. It is a WRONG NUMBER on the operator's screen: Sync health says work is
 * pending when nothing is going to happen, and the "skip brands already syncing" guard in
 * sync:daily treats the brand as busy for as long as the idempotency window lasts.
 *
 * ══ THE THRESHOLD ══
 * A row is only reaped once it is older than the longest a legitimate job could take to START.
 * Not to finish — to start. Horizon's worker timeout is 3600s and queue retry_after is 3700s, so
 * a job re-reserved after a crash could sit for ~1h before running. The default 2h is comfortably
 * past that, so we never reap something that was merely waiting its turn.
 *
 * Reaped rows are marked `failed` with an explicit reason — not deleted. A silent disappearance
 * would just be a different lie.
 *
 *   php artisan sync:reap-stale                # reap rows older than 2h
 *   php artisan sync:reap-stale --minutes=30   # more aggressive
 *   php artisan sync:reap-stale --dry-run      # look first
 */
class SyncReapStaleLogsCommand extends Command
{
    protected $signature = 'sync:reap-stale '
        . '{--minutes=120 : reap queued/running rows older than this many minutes} '
        . '{--dry-run : list what would be reaped, change nothing}';

    protected $description = 'Mark orphaned sync_logs (queued/running with no job behind them) as failed, so Sync health stops lying.';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $dry     = (bool) $this->option('dry-run');
        $cutoff  = now()->subMinutes($minutes);

        $stale = SyncLog::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No stale sync logs older than {$minutes} minutes. Nothing to reap.");

            return self::SUCCESS;
        }

        $this->warn("{$stale->count()} sync log(s) stuck in queued/running for over {$minutes} minutes:");

        foreach ($stale->take(20) as $log) {
            $this->line(sprintf(
                '  · #%d  brand %d  %-8s  %s  (%s, created %s)',
                $log->id,
                $log->brand_id,
                $log->platform,
                $log->target_date,
                $log->status,
                $log->created_at?->diffForHumans() ?? '?',
            ));
        }
        if ($stale->count() > 20) {
            $this->line('  … and ' . ($stale->count() - 20) . ' more.');
        }

        if ($dry) {
            $this->newLine();
            $this->info('DRY RUN — nothing changed. Re-run without --dry-run to reap.');

            return self::SUCCESS;
        }

        // Marked FAILED, not deleted. The operator deserves to see that the work was lost, and
        // why — a row that quietly vanishes is just a different kind of lie.
        $reaped = SyncLog::query()
            ->whereIn('id', $stale->pluck('id'))
            ->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => 'Orphaned: no worker ever picked this job up (queue restarted or flushed). Re-run the sync.',
            ]);

        $this->newLine();
        $this->info("Reaped {$reaped} orphaned sync log(s). Sync health now reflects reality.");
        $this->line('Re-run `php artisan sync:daily` to re-queue the affected brand-days.');

        return self::SUCCESS;
    }
}
