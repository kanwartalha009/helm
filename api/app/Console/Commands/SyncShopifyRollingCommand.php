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
 * Twice-daily Shopify auto-sync — runs at 01:00 and 13:00 UTC.
 *
 * For every active brand × active Shopify connection, dispatches one
 * SyncBrandDayJob each for TODAY and YESTERDAY in the brand's timezone.
 * Two days because the 12h cycle straddles midnight in every timezone,
 * and re-upserting yesterday is cheap (composite key on brand_id+platform
 * +date) and catches late-attribution refunds inside the same day Shopify
 * settles them.
 *
 * Why per-day (SyncBrandDayJob) rather than full-history (SyncBrandHistoryJob):
 * history is a paginated all-time scan — correct for first install, wasteful
 * twice a day. The per-day job hits one Shopify query for the date window.
 *
 * Skips any brand that already has queued/running sync_logs in the
 * idempotency window so this cron doesn't pile on top of operator clicks.
 *
 * See specs/CHANGE_REQUEST_2026-05-31_sync.md.
 */
class SyncShopifyRollingCommand extends Command
{
    /** See SyncStatusController::IDEMPOTENCY_WINDOW_MINUTES — kept in sync by convention. */
    private const IDEMPOTENCY_WINDOW_MINUTES = 30;

    /** Seconds between brand fan-outs. 15s ≈ 25 min spread across 100 brands. */
    private const STAGGER_SECONDS = 15;

    protected $signature = 'sync:shopify-rolling {--brand= : Only sync this brand slug}';
    protected $description = 'Twice-daily Shopify auto-sync for every active brand (today + yesterday).';

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

        // One query for every Shopify connection across the brand set —
        // avoids N+1 lookups at 100+ stores.
        $connectionsByBrand = PlatformConnection::query()
            ->whereIn('brand_id', $brands->pluck('id'))
            ->where('platform', 'shopify')
            ->where('status', 'active')
            ->get()
            ->groupBy('brand_id');

        $busyBrandIds = SyncLog::query()
            ->whereIn('brand_id', $brands->pluck('id'))
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(self::IDEMPOTENCY_WINDOW_MINUTES))
            ->pluck('brand_id')
            ->unique()
            ->all();

        $dispatched           = 0;
        $brandsSynced         = 0;
        $brandsAlreadyRunning = 0;
        $brandsNoShopify      = 0;
        $staggerSeconds       = 0;

        foreach ($brands as $brand) {
            if (in_array($brand->id, $busyBrandIds, true)) {
                $brandsAlreadyRunning++;
                continue;
            }

            $connections = $connectionsByBrand->get($brand->id) ?? collect();
            if ($connections->isEmpty()) {
                $brandsNoShopify++;
                continue;
            }

            $brandsSynced++;
            $today     = CarbonImmutable::now($brand->timezone)->startOfDay();
            $yesterday = $today->subDay();
            $delayAt   = now()->addSeconds($staggerSeconds);

            foreach ($connections as $conn) {
                foreach ([$yesterday, $today] as $day) {
                    $log = SyncLog::create([
                        'brand_id'    => $brand->id,
                        'platform'    => 'shopify',
                        'target_date' => $day->toDateString(),
                        'status'      => 'queued',
                        'started_at'  => null,
                    ]);
                    SyncBrandDayJob::dispatch($brand, $conn, $day, $log->id)
                        ->delay($delayAt);
                    $dispatched++;
                }
            }

            $staggerSeconds += self::STAGGER_SECONDS;
        }

        $this->info(sprintf(
            'Dispatched %d Shopify day-sync job(s) across %d brand(s); skipped %d already syncing, %d with no Shopify connection.',
            $dispatched,
            $brandsSynced,
            $brandsAlreadyRunning,
            $brandsNoShopify
        ));

        return self::SUCCESS;
    }
}
