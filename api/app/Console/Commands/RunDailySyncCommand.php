<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncBrandDayJob;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily sync — runs at 13:00 UTC. For every active brand × active
 * connection, dispatches SyncBrandDayJob for yesterday and the 6 prior days.
 *
 * The rolling 7-day window catches late refunds on Shopify and late
 * conversions on ad platforms. Upserts mean re-syncing a day is safe.
 *
 * Skips any brand that already has queued/running sync_logs in the
 * idempotency window so the 13:00 daily doesn't pile on top of work an
 * operator just kicked off manually a minute ago.
 */
class RunDailySyncCommand extends Command
{
    /** See SyncStatusController::IDEMPOTENCY_WINDOW_MINUTES — kept in sync by convention. */
    private const IDEMPOTENCY_WINDOW_MINUTES = 30;

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

            $connections = PlatformConnection::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->get();

            $today = CarbonImmutable::now($brand->timezone)->startOfDay();
            // yesterday + 6 prior days = 7 days total
            for ($i = 1; $i <= 7; $i++) {
                $day = $today->subDays($i);
                foreach ($connections as $conn) {
                    $log = SyncLog::create([
                        'brand_id'    => $brand->id,
                        'platform'    => $conn->platform,
                        'target_date' => $day->toDateString(),
                        'status'      => 'queued',
                        'started_at'  => null,
                    ]);
                    SyncBrandDayJob::dispatch($brand, $conn, $day, $log->id);
                    $dispatched++;
                }
            }
        }

        $this->info("Dispatched {$dispatched} SyncBrandDayJob(s) across {$brands->count()} brand(s); skipped {$skipped} brand(s) already syncing.");
        return self::SUCCESS;
    }
}
