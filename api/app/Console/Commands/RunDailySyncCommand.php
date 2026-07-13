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

        $skipped = 0;

        // Resolve every brand's connections up front (one query, not N), and drop the brands we
        // are skipping — so the dispatch loop below is pure ordering with no side lookups.
        /** @var array<int, array{brand: \App\Models\Brand, conns: \Illuminate\Support\Collection}> $targets */
        $targets = [];
        foreach ($brands as $brand) {
            if (in_array($brand->id, $busyBrandIds, true)) {
                $skipped++;
                continue;
            }

            $conns = PlatformConnection::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->get();

            if ($conns->isNotEmpty()) {
                $targets[] = ['brand' => $brand, 'conns' => $conns];
            }
        }

        // ══ DISPATCH ORDER IS THE WHOLE POINT ══
        //
        // This used to loop brand-outer, day-inner: brand #1 got all SEVEN of its days queued
        // before brand #2 got yesterday. With 88 brands that put the last brand's YESTERDAY —
        // the only day the dashboard actually shows — roughly a thousand jobs deep. Bosco
        // watched the dashboard fill one brand at a time while the queue ground through six days
        // of history nobody was looking at.
        //
        // Inverting the loops fixes it, because Redis is FIFO: DAY-outer, brand-inner means every
        // brand's yesterday is enqueued before ANY brand's day-before-yesterday. The dashboard
        // fills across all 88 brands first; the older days (which exist only to catch
        // late-attributing ad conversions, and which the operator is not staring at) drain after.
        //
        // Same trick as SyncBrandEnrichmentJob, applied to the date axis instead of the dataset.
        $dispatched = 0;
        for ($i = 1; $i <= 7; $i++) {       // i = 1 → yesterday, the day the dashboard reads
            foreach ($targets as $t) {
                /** @var \App\Models\Brand $brand */
                $brand = $t['brand'];
                $day   = CarbonImmutable::now($brand->timezone)->startOfDay()->subDays($i);

                foreach ($t['conns'] as $conn) {
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
        $this->line('Day-major order: every brand\'s yesterday is queued before any brand\'s older days.');

        return self::SUCCESS;
    }
}
