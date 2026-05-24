<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillBrandRangeJob;
use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Backfill a brand across an arbitrary date range.
 *
 *   php artisan brand:backfill {brand-slug} --from=YYYY-MM-DD --to=YYYY-MM-DD
 */
class BackfillBrandCommand extends Command
{
    protected $signature = 'brand:backfill
        {brand : Brand slug or id}
        {--from= : ISO date YYYY-MM-DD (inclusive)}
        {--to= : ISO date YYYY-MM-DD (inclusive)}';

    protected $description = 'Backfill historical metrics for a brand across every active connection.';

    public function handle(): int
    {
        $key = $this->argument('brand');
        $brand = Brand::query()
            ->withoutGlobalScopes()
            ->where(is_numeric($key) ? 'id' : 'slug', $key)
            ->first();

        if (! $brand) {
            $this->error("Brand not found: {$key}");
            return self::FAILURE;
        }

        $fromOpt = $this->option('from');
        $toOpt   = $this->option('to');

        if (! $fromOpt || ! $toOpt) {
            $this->error('--from and --to are required.');
            return self::FAILURE;
        }

        $from = CarbonImmutable::parse($fromOpt, $brand->timezone)->startOfDay();
        $to   = CarbonImmutable::parse($toOpt, $brand->timezone)->endOfDay();

        if ($from > $to) {
            $this->error('--from must be on or before --to.');
            return self::FAILURE;
        }

        BackfillBrandRangeJob::dispatch($brand, $from, $to);

        $this->info("Dispatched backfill for {$brand->slug} from {$from->toDateString()} to {$to->toDateString()}.");
        return self::SUCCESS;
    }
}
