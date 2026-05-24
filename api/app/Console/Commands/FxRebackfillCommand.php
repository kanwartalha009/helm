<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillFxRatesJob;
use Illuminate\Console\Command;

/**
 * Dispatches BackfillFxRatesJob to fill in fx_rate_to_usd on any
 * daily_metrics rows flagged metadata.fx_pending=true.
 *
 *   php artisan fx:rebackfill        # async (queues on default)
 *   php artisan fx:rebackfill --sync # runs inline; blocks until done
 */
class FxRebackfillCommand extends Command
{
    protected $signature = 'fx:rebackfill {--sync : Run the job inline instead of queueing it}';
    protected $description = 'Backfill fx_rate_to_usd on daily_metrics rows previously flagged as fx_pending.';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Running BackfillFxRatesJob inline…');
            BackfillFxRatesJob::dispatchSync();
            $this->info('Done.');
            return self::SUCCESS;
        }

        BackfillFxRatesJob::dispatch();
        $this->info('BackfillFxRatesJob queued.');
        return self::SUCCESS;
    }
}
