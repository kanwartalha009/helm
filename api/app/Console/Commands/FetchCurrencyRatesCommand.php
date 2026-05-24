<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchDailyCurrencyRatesJob;
use Illuminate\Console\Command;

/**
 * Dispatches the FX rate fetch job. Scheduled at 13:30 UTC daily, but
 * also runnable on demand:
 *
 *   php artisan fx:fetch
 */
class FetchCurrencyRatesCommand extends Command
{
    protected $signature = 'fx:fetch';
    protected $description = 'Fetch yesterday\'s currency rates from exchangerate.host.';

    public function handle(): int
    {
        FetchDailyCurrencyRatesJob::dispatch();
        $this->info('FetchDailyCurrencyRatesJob dispatched.');
        return self::SUCCESS;
    }
}
