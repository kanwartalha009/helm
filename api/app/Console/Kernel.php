<?php

declare(strict_types=1);

namespace App\Console;

use App\Models\SyncLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Schedule from spec §12.1 / docs/06-sync — all times UTC.
 *
 *   13:00 daily       — RunDailySyncCommand   (7-day rolling)
 *   13:30 daily       — FetchCurrencyRatesCommand
 *   :00 hourly 06-22  — RunHourlySyncCommand   (top-20 hot brands)
 *   02:00 Sunday      — sync_logs cleanup > 90 days
 */
class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Daily sync — 7-day rolling window at 13:00 UTC.
        $schedule->command('sync:daily')
            ->dailyAt('13:00')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer();

        // FX rate fetch + backfill removed in Phase 1. Sync stores native
        // currency only; the dashboard renders each brand in its own
        // currency. Restore these schedules when (and if) USD aggregation
        // gets re-introduced — see docs/10-edge-cases / currency.

        // Hourly hot-brands sync — every hour from 06:00 to 22:00 UTC.
        $schedule->command('sync:hourly')
            ->hourlyAt(0)
            ->between('06:00', '22:00')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer();

        // sync_logs cleanup — Sunday 02:00 UTC, delete rows older than 90 days.
        $schedule->call(function (): void {
            SyncLog::where('created_at', '<', now()->subDays(90))->delete();
        })
            ->name('sync_logs.cleanup')
            ->weeklyOn(0, '02:00')
            ->timezone('UTC')
            ->onOneServer();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
