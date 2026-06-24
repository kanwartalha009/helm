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
        // Twice-daily sync — 7-day rolling window per brand × connection.
        $schedule->command('sync:daily')
            ->twiceDailyAt(1, 13, 0)
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
