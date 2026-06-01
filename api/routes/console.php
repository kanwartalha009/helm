<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Console routes
|--------------------------------------------------------------------------
|
| Laravel 11 scheduling lives in bootstrap/app.php (->withSchedule()), not
| here and not in a Console\Kernel. This file only registers ad-hoc Artisan
| closures. The cron, defined in bootstrap/app.php, runs:
|
|   01:00 + 13:00 UTC — sync:daily        (7-day rolling, every active brand × active connection)
|   Sun  02:00   UTC — sync_logs cleanup  (delete rows older than 90 days)
|
| Twice-daily is the ratified cadence — see
| specs/CHANGE_REQUEST_2026-05-31_sync.md. Manual "Sync now" in the SPA/API
| covers on-demand refresh between cron runs.
*/

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    /** @var \Illuminate\Console\Command $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
