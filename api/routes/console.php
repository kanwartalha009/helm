<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Console routes — Closure-style scheduling lives in Kernel::schedule().
|--------------------------------------------------------------------------
|
| This file is kept thin on purpose. The real schedule definitions are in
| App\Console\Kernel and match docs/06-sync exactly:
|
|   01:00 UTC  — sync:shopify-rolling  (today + yesterday, Shopify only)
|   13:00 UTC  — sync:shopify-rolling  (today + yesterday, Shopify only)
|   13:00 UTC  — sync:daily            (7-day rolling, all platforms)
|   13:30 UTC  — fx:fetch              (removed in Phase 1)
|   :00 06-22  — sync:hourly           (top-20 hot brands, today only)
|   Sun 02:00  — sync_logs cleanup > 90 days
*/

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    /** @var \Illuminate\Console\Command $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
