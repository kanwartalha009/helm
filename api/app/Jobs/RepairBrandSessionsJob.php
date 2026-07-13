<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\ShopifyBackfillSessionTrafficCommand;
use App\Models\BackfillRun;
use App\Models\Brand;
use App\Models\SessionTrafficDaily;
use App\Services\Sync\SessionTrafficSync;
use App\Support\BackfillCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * "Fill missing days" on the Inventory sessions strip.
 *
 * The sessions read gate is all-or-nothing by design: every day in the window must exist AND have
 * reconciled against Shopify's own store total, or the whole window renders "—" (a 30-day window
 * holding 28 synced days would under-report every product by ~7% while looking exact). The cost of
 * that honesty is that ONE bad day blanks the window — so the operator needs a way to fix that day
 * without an SSH session. Telling a customer to run `php artisan …` is not a UI.
 *
 * ══ WHY THIS RE-PULLS ONLY THE BROKEN DAYS ══
 * Session traffic costs ~5 throttled ShopifyQL calls per brand-day, so re-pulling a whole 30-day
 * window to fix 2 days would turn a click into ten minutes of API budget for no gain. This resolves
 * the exact set of days that are missing or `is_complete = false` and pulls precisely those.
 *
 * A day that is already reconciled is NEVER re-pulled — it cannot get any better, and re-pulling it
 * is pure cost.
 */
class RepairBrandSessionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;      // the operator retries via the button; never blind auto-retry
    public int $timeout = 1800;

    /** Distinct from RefreshBrandInventoryJob::DATASET so the two buttons poll independently. */
    public const DATASET = 'session-repair';

    /**
     * A click must not become an hour. The UI windows top out at 31 days; a hand-typed custom range
     * could be a year, and re-pulling 365 days synchronously behind a spinner is not a repair, it's
     * a backfill. Anything past this is the operator's job via the CLI, and we say so.
     */
    private const MAX_DAYS = 62;

    public function __construct(
        public readonly Brand $brand,
        public readonly int $runId,
        public readonly string $from,   // Y-m-d, brand tz — the window the user is looking at
        public readonly string $to,
    ) {
        $this->onQueue('shopify-sync');
    }

    public function handle(SessionTrafficSync $sync, BackfillCoverage $coverage): void
    {
        $run = BackfillRun::find($this->runId);
        if ($run === null || $run->status !== 'queued') {
            return; // cancelled, or a duplicate click already handled it
        }

        $run->update(['status' => 'running', 'started_at' => now(), 'message' => 'Checking which days are missing…']);

        $conn = $this->brand->connections()->where('platform', 'shopify')->where('status', 'active')->first();
        if ($conn === null) {
            $run->update([
                'status'      => 'done',
                'finished_at' => now(),
                'message'     => 'This brand has no active Shopify connection, so sessions cannot be pulled.',
            ]);

            return;
        }

        $broken = $this->brokenDays();

        if ($broken === []) {
            // Not a failure. The window is whole and the page should already be rendering numbers —
            // if it isn't, the bug is in the read path, not the data, and saying "done, 0 days" is
            // the honest way to point at that.
            $run->update([
                'status'      => 'done',
                'finished_at' => now(),
                'message'     => 'Nothing to fill — every day in this window is already reconciled.',
            ]);

            return;
        }

        $total  = count($broken);
        $filled = 0;
        $failed = [];

        try {
            foreach ($broken as $i => $day) {
                // Written BEFORE the day is pulled, so the UI names the day being worked on right
                // now rather than the one that just finished.
                $run->update(['message' => sprintf('%d/%d · %s', $i + 1, $total, $day)]);

                // null = we learned NOTHING (transport error, or the day would not reconcile).
                // It must NOT be marked covered — marking a day we failed to establish is how a
                // gap becomes permanent and invisible.
                $written = $sync->syncDay($conn, $day);

                if ($written === null) {
                    $failed[] = $day;
                    continue;
                }

                // The SAME dataset key the CLI backfill uses — so a day filled from this button is
                // recorded as covered and the next backfill run skips it, instead of paying ~5
                // ShopifyQL calls to re-learn what we already know.
                $coverage->mark(
                    $this->brand->id,
                    ShopifyBackfillSessionTrafficCommand::DATASET,
                    '',
                    $day,
                    $day,
                    $written,
                );
                $filled++;
            }

            $run->update([
                'status'      => 'done',
                'finished_at' => now(),
                'message'     => $this->summary($total, $filled, $failed),
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

    /**
     * The days in this window that are missing entirely, or stored but unreconciled.
     *
     * "Stored but unreconciled" is the subtle one: those days DO have rows and ARE marked done in
     * backfill_coverage, so a plain resume-aware backfill skips them forever. They are exactly the
     * days that blank the window, so they are exactly the days this button exists to fix.
     *
     * @return list<string> Y-m-d, ascending
     */
    private function brokenDays(): array
    {
        $from = CarbonImmutable::parse($this->from)->startOfDay();
        $to   = CarbonImmutable::parse($this->to)->startOfDay();

        if ($to->lessThan($from)) {
            return [];
        }

        // Every day that currently reconciles. Anything in the window NOT in this set is broken —
        // whether it has no rows at all or has rows flagged is_complete = false.
        $good = SessionTrafficDaily::query()
            ->where('brand_id', $this->brand->id)
            ->where('is_complete', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse((string) $d)->toDateString())
            ->flip();

        $out = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $day = $d->toDateString();
            if (! $good->has($day)) {
                $out[] = $day;
            }
            if (count($out) >= self::MAX_DAYS) {
                break;
            }
        }

        return $out;
    }

    /** @param list<string> $failed */
    private function summary(int $total, int $filled, array $failed): string
    {
        if ($failed === []) {
            return "Filled {$filled} of {$total} day(s). This window should now show sessions.";
        }

        // Days that still won't reconcile are NOT hidden behind a green tick. If Shopify keeps
        // refusing to make a day add up, the operator has to know which day and that it persists.
        $list = implode(', ', array_slice($failed, 0, 8)) . (count($failed) > 8 ? ' …' : '');

        return "Filled {$filled} of {$total} day(s). " . count($failed) . ' day(s) still would not reconcile '
            . "against Shopify's own total and remain excluded: {$list}. Re-run to retry — if a day fails "
            . 'repeatedly, Shopify cannot break that day down by landing page.';
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
                'message'     => mb_substr('Fill failed: ' . $e->getMessage(), 0, 2000),
            ]);
    }
}
