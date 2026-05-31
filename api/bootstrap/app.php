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
    | Laravel 11 schedules live HERE — App\Console\Kernel is obsolete on 11.x
    | and is ignored at boot. Before this block existed, `schedule:list` was
    | empty and NOTHING ran on cron. (See app/Console/Kernel.php — kept only
    | as historical reference; safe to delete.)
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
