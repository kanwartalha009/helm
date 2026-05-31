<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncBrandDayJob;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly sync — runs every 60 minutes 06:00–22:00 UTC. For "hot" brands
 * (top-20 by spend yesterday), dispatches SyncBrandDayJob for TODAY only.
 *
 * Today's row is overwritten via upsert every hour as the day progresses.
 * `is_complete` stays false until the daily sync at 13:00 the next day.
 *
 * Skips any hot brand that already has queued/running sync_logs in the
 * idempotency window so two crons firing close together (clock skew on
 * Cloudways supervisor sometimes overlaps minute boundaries) can't double-
 * dispatch.
 */
class RunHourlySyncCommand extends Command
{
    /** See SyncStatusController::IDEMPOTENCY_WINDOW_MINUTES — kept in sync by convention. */
    private const IDEMPOTENCY_WINDOW_MINUTES = 30;

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

        $busyBrandIds = SyncLog::query()
            ->whereIn('brand_id', $brands->pluck('id'))
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(self::IDEMPOTENCY_WINDOW_MINUTES))
            ->pluck('brand_id')
            ->unique()
            ->all();

        $dispatched = 0;
        $skipped    = 0;
        foreach ($brands as $brand) {
            if (in_array($brand->id, $busyBrandIds, true)) {
                $skipped++;
                continue;
            }

            $today = CarbonImmutable::now($brand->timezone)->startOfDay();
            $connections = PlatformConnection::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->get();

            foreach ($connections as $conn) {
                $log = SyncLog::create([
                    'brand_id'    => $brand->id,
                    'platform'    => $conn->platform,
                    'target_date' => $today->toDateString(),
                    'status'      => 'queued',
                    'started_at'  => null,
                ]);
                SyncBrandDayJob::dispatch($brand, $conn, $today, $log->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} hourly SyncBrandDayJob(s); skipped {$skipped} brand(s) already syncing.");
        return self::SUCCESS;
    }
}
