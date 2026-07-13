<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserCanAccessBrand;
use App\Models\SyncLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:     __DIR__ . '/../routes/web.php',
        api:     __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health:  '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'         => EnsureRole::class,
            'access.brand' => EnsureUserCanAccessBrand::class,
        ]);

        // Pure bearer-token auth. The SPA stores the Sanctum token in
        // localStorage and sends it as `Authorization: Bearer <token>`.
        // We intentionally don't call $middleware->statefulApi() — that
        // wraps /api/* in the web stack and enforces CSRF, which causes
        // "CSRF token mismatch" 419s on every request from the SPA.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    /*
    |--------------------------------------------------------------------------
    | Schedule (Laravel 11)
    |--------------------------------------------------------------------------
    |
    | Laravel 11 schedules live HERE — there is no App\Console\Kernel on 11.x
    | (any such class is ignored at boot). This block is the single source of
    | truth for cron; the obsolete app/Console/Kernel.php is neutralised (delete
    | it for good with `git rm api/app/Console/Kernel.php`).
    | Before this block existed, `schedule:list` was empty and NOTHING ran.
    |
    | Cadence: every 12 hours (01:00 / 13:00 UTC). Retainer client wanted
    | fresher numbers than the spec's once-daily without paying the hourly
    | sync cost. Jobs land on the queue; the queue:work cron drains them.
    |
    | Required system cron (Cloudways → Cron Job Management):
    |
    |   * * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
    |
    */
    ->withSchedule(function (Schedule $schedule): void {
        /*
         * SCHEDULER HEARTBEAT — one line a minute, and it exists for a reason.
         *
         * On 2026-07-13 we discovered production had been running with NO system cron: the
         * schedule was perfect, `schedule:list` looked healthy, and nothing had ever invoked it.
         * Bosco had been manually syncing every morning for months. The failure was invisible
         * because a scheduler that never runs looks EXACTLY like a scheduler with nothing due.
         *
         * `storage/logs/schedule.log` can't answer the question either — it's only written when a
         * scheduled command actually fires, so an empty file is ambiguous.
         *
         * This writes a timestamp every minute. The file's mtime IS the answer:
         *
         *     tail -1 storage/logs/scheduler-heartbeat.log     → when the scheduler last ran
         *
         * Older than ~2 minutes = the system cron is dead. No guessing, no ambiguity.
         * Cost: one file write a minute.
         */
        $schedule->call(function (): void {
            file_put_contents(
                storage_path('logs/scheduler-heartbeat.log'),
                now()->toIso8601String() . PHP_EOL,
            );
        })->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping();

        // Twice-daily sync — 7-day rolling window per brand × connection.
        $schedule->command('sync:daily')
            ->twiceDailyAt(1, 13, 0)
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Twice-daily Shopify TODAY+YESTERDAY refresh (CR 2026-05-31, ratified
        // as D-018 on 2026-07-10). Runs at 03:00/15:00 — NOT the CR's 01:00/13:00,
        // because sync:daily now owns those slots and both commands skip brands
        // with queued/running sync_logs (30-min idempotency): fired together,
        // whichever dispatches second would skip everything. Two hours is enough
        // for the daily fan-out to drain at current scale before this fires.
        // This is what keeps the brand-page "today" tile from going stale.
        $schedule->command('sync:shopify-rolling')
            ->twiceDailyAt(3, 15, 0)
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Market Ad Library corpus — 02:30 UTC nightly (Ads Library Phase 2).
        // Sweeps tracked competitor pages + due saved searches within the hourly
        // call budget, hard-stops at 06:00 UTC. No-op until an Ad Library token is
        // configured (the command self-skips).
        $schedule->command('adlib:refresh')
            ->dailyAt('02:30')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // FX rates — 13:30 UTC, just after the 13:00 sync. Pulls yesterday's
        // native->USD rates for every active brand currency into currency_rates,
        // then (13:45) sweeps any rows that synced before the rate existed.
        // USD aggregation is a Phase 1 acceptance item (docs/12).
        $schedule->command('fx:fetch')
            ->dailyAt('13:30')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        $schedule->command('fx:rebackfill')
            ->dailyAt('13:45')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Inventory snapshot — once daily for the dead-stock report. 14:00 UTC,
        // after the 13:00 sync so the trailing sell-through reflects the latest
        // day. Captures stock + units sold by product and collection.
        $schedule->command('shopify:sync-inventory')
            ->dailyAt('14:00')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Shopify product catalog snapshot (stock + variants + handle↔title)
        // into product_catalog — the stock the Inventory Intelligence page
        // shows. 14:10 UTC, after the 13:00 sync, beside the 14:00 inventory
        // snapshot. Without this the catalog only refreshed when an operator
        // ran shopify:sync-catalog by hand, so stock went stale silently.
        $schedule->command('shopify:sync-catalog')
            ->dailyAt('14:10')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Creative thumbnails: refresh the CDN links BEFORE they expire. Meta and TikTok both
        // return short-lived signed URLs, and the daily sync only writes TODAY's rows — so an ad
        // that ran three weeks ago is still on screen in the 30-day Creatives view with a URL that
        // quietly dies, and the card goes blank. This re-resolves assets (no insights call, so no
        // reporting quota) for every ad in the window and rewrites the URL on all its rows.
        // 14:20 UTC, right after the catalog snapshot and before the 15:00 rolling sync.
        $schedule->command('creatives:refresh-thumbnails')
            ->dailyAt('14:20')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Anomaly scan (GO-2.4). 15:30 UTC — after the 15:00 rolling sync and the
        // 14:10 catalog refresh, so the day it scans is as complete as it will get.
        // Deterministic rules only; idempotent, so a re-run refreshes rather than
        // duplicates. Every rule stays silent without a 14-day baseline.
        $schedule->command('anomalies:scan')
            ->dailyAt('15:30')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // THE LEDGER (GO-2.5) — silent writer. 15:45 UTC, right after anomalies:scan, so
        // the night's anomalies are already open and get logged with the rest.
        // Insert-only and idempotent: advice already open is NOT re-recorded, so the
        // acceptance rate is never diluted by duplicate rows. Nothing renders these yet —
        // the track record (GO-3.3) can only be computed from history that already exists,
        // which is exactly why this ships before the UI that needs it.
        $schedule->command('ledger:record')
            ->dailyAt('15:45')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Grade Helm's OWN advice (GO-3.3). 16:00 UTC, after ledger:record. Measures
        // accepted/dismissed recommendations at 14/30 days against their frozen
        // baselines, and expires advice nobody ever decided on. Outcomes are written
        // once — a loss can never be re-graded into a win.
        $schedule->command('ledger:measure')
            ->dailyAt('16:00')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // Weekly digest (GO-3.5). Monday 08:00 UTC — the start of the working week, after
        // the weekend's syncs, ledger writes and measurements have all landed. Sends to
        // Slack only if a webhook is configured; a missing webhook is NOT an error, and a
        // Slack outage never fails the scheduler.
        $schedule->command('digest:weekly')
            ->weeklyOn(1, '08:00')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'));

        // sync_logs retention — keep last 90 days. Sunday 02:00 UTC.
        $schedule->call(function (): void {
            SyncLog::where('created_at', '<', now()->subDays(90))->delete();
        })
            ->name('sync_logs.cleanup')
            ->weeklyOn(0, '02:00')
            ->timezone('UTC')
            ->onOneServer();
    })
    ->create();
