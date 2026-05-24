<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncBrandDayJob;
use App\Models\Brand;
use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily sync — runs at 13:00 UTC. For every active brand × active
 * connection, dispatches SyncBrandDayJob for yesterday and the 6 prior days.
 *
 * The rolling 7-day window catches late refunds on Shopify and late
 * conversions on ad platforms. Upserts mean re-syncing a day is safe.
 */
class RunDailySyncCommand extends Command
{
    protected $signature = 'sync:daily {--brand= : Only sync this brand slug}';
    protected $description = 'Dispatch the daily sync (yesterday + 6 prior days) for every active brand.';

    public function handle(): int
    {
        $brands = Brand::query()
            ->withoutGlobalScopes()
            ->where('status', 'active')
            ->when($this->option('brand'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($brands->isEmpty()) {
            $this->warn('No active brands to sync.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($brands as $brand) {
            $connections = PlatformConnection::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->get();

            $today = CarbonImmutable::now($brand->timezone)->startOfDay();
            // yesterday + 6 prior days = 7 days total
            for ($i = 1; $i <= 7; $i++) {
                $day = $today->subDays($i);
                foreach ($connections as $conn) {
                    SyncBrandDayJob::dispatch($brand, $conn, $day);
                    $dispatched++;
                }
            }
        }

        $this->info("Dispatched {$dispatched} SyncBrandDayJob(s) across {$brands->count()} brand(s).");
        return self::SUCCESS;
    }
}
