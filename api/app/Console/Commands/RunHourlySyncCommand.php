<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncBrandDayJob;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly sync — runs every 60 minutes 06:00–22:00 UTC. For "hot" brands
 * (top-20 by spend yesterday), dispatches SyncBrandDayJob for TODAY only.
 *
 * Today's row is overwritten via upsert every hour as the day progresses.
 * `is_complete` stays false until the daily sync at 13:00 the next day.
 */
class RunHourlySyncCommand extends Command
{
    protected $signature = 'sync:hourly';
    protected $description = 'Dispatch today-sync for hot brands (top-20 by yesterday spend).';

    public function handle(): int
    {
        $limit = (int) config('sync.hot_brands_limit', 20);

        $yesterdayUtc = CarbonImmutable::now('UTC')->subDay()->toDateString();

        // Top-N brand_ids by yesterday's total spend across platforms.
        $hotBrandIds = DailyMetric::query()
            ->whereDate('date', $yesterdayUtc)
            ->whereNotNull('spend')
            ->select('brand_id', DB::raw('SUM(spend) as total_spend'))
            ->groupBy('brand_id')
            ->orderByDesc('total_spend')
            ->limit($limit)
            ->pluck('brand_id')
            ->all();

        if ($hotBrandIds === []) {
            $this->info('No hot brands found — skipping.');
            return self::SUCCESS;
        }

        $brands = Brand::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $hotBrandIds)
            ->where('status', 'active')
            ->get();

        $dispatched = 0;
        foreach ($brands as $brand) {
            $today = CarbonImmutable::now($brand->timezone)->startOfDay();
            $connections = PlatformConnection::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->get();

            foreach ($connections as $conn) {
                SyncBrandDayJob::dispatch($brand, $conn, $today);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} hourly SyncBrandDayJob(s).");
        return self::SUCCESS;
    }
}
