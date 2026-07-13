<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\ShopifyBackfillSessionTrafficCommand;
use App\Models\BackfillRun;
use App\Models\Brand;
use App\Models\SessionTrafficDay;
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
        $failed = [];   // day => the reason it is STILL unusable

        try {
            foreach ($broken as $i => $day) {
                // Written BEFORE the day is pulled, so the UI names the day being worked on right
                // now rather than the one that just finished.
                $run->update(['message' => sprintf('%d/%d · %s', $i + 1, $total, $day)]);

                $result = $sync->syncDay($conn, $day);

                // ══ BRANCH ON THE VERDICT, NOT ON A ROW COUNT ══
                // This is the bug that made the button lie. `syncDay` used to return the number of
                // rows written, and a day that FAILED reconciliation still writes rows — hundreds
                // of them. So "1,847 rows written" was read as success, the day was reported as
                // filled, and the window stayed blank. Row count says nothing about whether the
                // rows can be TRUSTED. `complete` is the only thing that answers the question the
                // operator is actually asking.
                if (! $result->complete) {
                    $failed[$day] = $result->reason();
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
                    $result->rowsWritten,
                );
                $filled++;
            }

            // A run that fixed NOTHING is not a success, and must not render like one. The old code
            // finished 'done' regardless, so the button flipped back to its resting state, the
            // operator saw a green path, and the window was still blank. If we could not close a
            // single day, that is a FAILURE and it says so in red.
            $run->update([
                'status'      => $filled === 0 ? 'failed' : 'done',
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

        // Every day that currently RECONCILES, read from the verdict table. Anything in the window
        // not in this set is broken — never pulled, pulled and failed, or pulled and short.
        //
        // Read from session_traffic_days, NOT from the breakdown rows: a genuinely zero-session day
        // has no rows but IS complete, and asking the breakdown table would call it broken and
        // re-pull it on every single click, forever, for nothing.
        $good = SessionTrafficDay::query()
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

    /**
     * What actually happened, in words an operator can act on.
     *
     * A failing day names ITSELF and its reason ("Shopify reports 5,709 sessions but its
     * landing-page breakdown only adds up to 5,208"). "Some days did not reconcile" is not a
     * message, it is a shrug — it tells the reader nothing they can do anything with, and it is
     * what sent us round the loop of clicking the button and re-reading the same amber warning.
     *
     * @param  array<string, string>  $failed  day => reason
     */
    private function summary(int $total, int $filled, array $failed): string
    {
        if ($failed === []) {
            return "Filled {$filled} of {$total} day(s). This window should now show sessions.";
        }

        $lines = [];
        foreach (array_slice($failed, 0, 6, true) as $day => $reason) {
            $lines[] = "· {$day} — {$reason}";
        }
        if (count($failed) > 6) {
            $lines[] = '· …and ' . (count($failed) - 6) . ' more.';
        }

        $head = $filled === 0
            ? "Could not fill any of the {$total} missing day(s)."
            : "Filled {$filled} of {$total} day(s); " . count($failed) . ' still cannot be used.';

        return $head . "\n" . implode("\n", $lines) . "\n"
            . 'A day that keeps failing is one Shopify will not break down by landing page — its '
            . 'sessions exist, but they cannot be attributed to products, so the window stays blank '
            . 'rather than showing a short total.';
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
