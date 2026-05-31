<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackfillRequest;
use App\Http\Resources\SyncLogResource;
use App\Jobs\BackfillBrandRangeJob;
use App\Jobs\SyncBrandDayJob;
use App\Jobs\SyncBrandHistoryJob;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncStatusController extends Controller
{
    /**
     * GET /api/sync/status
     *
     * Returns the 200 most recent sync logs plus aggregate counts so the
     * Sync health page can render its stat tiles without a second round-trip.
     */
    public function index(): JsonResponse
    {
        $logs = SyncLog::query()
            ->with('brand')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $counts = SyncLog::query()
            ->selectRaw('status, count(*) as c')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return response()->json([
            'logs'   => SyncLogResource::collection($logs)->resolve(),
            'counts' => [
                'successful' => (int) ($counts['success'] ?? 0),
                'failed'     => (int) ($counts['failed'] ?? 0),
                'running'    => (int) ($counts['running'] ?? 0),
                'queued'     => (int) ($counts['queued'] ?? 0),
            ],
        ]);
    }

    /**
     * POST /api/brands/{brand}/sync — manual trigger.
     *
     * Shopify connections → single SyncBrandHistoryJob that paginates ALL
     * orders the store will hand back, groups them per-day in the brand's
     * timezone, and upserts one daily_metrics row per day. Today is recorded
     * with `is_complete=false`. This is what makes a freshly installed store
     * show real numbers on the dashboard the moment the sync finishes.
     *
     * Ad connections → 7-day rolling fan-out (today + 6 days back). These
     * adapters aren't implemented yet so most will throw into sync_logs, but
     * the dispatch path is in place for when they come online.
     *
     * The nightly cron still owns the canonical 7-day rolling backfill —
     * see RunDailySyncCommand. That's where late-refund attribution settles.
     */
    public function trigger(Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $today = CarbonImmutable::now($brand->timezone)->startOfDay();
        $from  = $today->subDays(6);

        $connections = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('status', '!=', 'paused')
            ->get();

        $dispatched = 0;
        foreach ($connections as $conn) {
            if ($conn->platform === 'shopify') {
                // Queue the history scan on Horizon. We previously ran this
                // inline (dispatchSync) so the response held populated data,
                // but PHP's max_execution_time (30s default) kills the request
                // for any store with more than a few hundred orders. Async
                // means the response returns in milliseconds and the brand
                // page's polling picks up data as it lands.
                SyncBrandHistoryJob::dispatch($brand, $conn);
                $dispatched++;
                continue;
            }
            // Ad platforms — fan out one day at a time across the 7-day window.
            // These stay queued because their adapters aren't implemented yet
            // and we don't want to block the request on failing jobs.
            for ($d = $from; $d->lessThanOrEqualTo($today); $d = $d->addDay()) {
                SyncBrandDayJob::dispatch($brand, $conn, $d);
                $dispatched++;
            }
        }

        return response()->json([
            'dispatched' => $dispatched,
            'mode'       => 'history-for-shopify+7d-for-ads',
            'from'       => $from->toDateString(),
            'to'         => $today->toDateString(),
            'platforms'  => $connections->pluck('platform')->all(),
        ]);
    }

    /**
     * POST /api/sync/all — fan-out manual sync for every active brand.
     *
     * Mirrors the per-brand `trigger` logic exactly so a click of the master
     * "Sync now" on the main dashboard behaves identically to clicking it on
     * each brand page in turn:
     *   - Shopify connections → SyncBrandHistoryJob (paginated all-time scan)
     *   - Ad connections      → SyncBrandDayJob × 7 (today + 6 days back)
     *
     * Authorization is enforced upstream by the `role:master_admin,manager`
     * middleware on the route (see routes/api.php). Lower-tier users still
     * sync via the per-brand Sync now on the brand page.
     */
    public function triggerAll(): JsonResponse
    {
        $brands = Brand::query()
            ->where('status', 'active')
            ->get();

        if ($brands->isEmpty()) {
            return response()->json([
                'dispatched' => 0,
                'brands'     => 0,
                'message'    => 'No active brands to sync.',
            ]);
        }

        $brandIds = $brands->pluck('id')->all();

        // One query for every connection across the brand set so we don't
        // hammer the DB with N+1 lookups during a fan-out that might touch
        // 100+ stores. Group in PHP after.
        $connectionsByBrand = PlatformConnection::query()
            ->whereIn('brand_id', $brandIds)
            ->where('status', '!=', 'paused')
            ->get()
            ->groupBy('brand_id');

        $dispatched      = 0;
        $brandsSynced    = 0;
        $brandsSkipped   = 0;

        // Stagger dispatch by N seconds per brand so a fan-out across 17+
        // stores doesn't all hit Shopify within the same minute. The queue
        // worker still processes serially, but adding a delay means a
        // failure on brand #4 doesn't push brand #5 onto a retry minute
        // when Shopify is throttling. 30s feels right at ~100 brands:
        // 100 × 30s = 50 min spread, well inside the 12h sync window.
        $staggerSeconds  = 0;
        $perBrandStagger = 30;

        foreach ($brands as $brand) {
            $connections = $connectionsByBrand->get($brand->id) ?? collect();
            if ($connections->isEmpty()) {
                $brandsSkipped++;
                continue;
            }

            $brandsSynced++;
            $today = CarbonImmutable::now($brand->timezone)->startOfDay();
            $from  = $today->subDays(6);

            $delayAt = now()->addSeconds($staggerSeconds);

            foreach ($connections as $conn) {
                if ($conn->platform === 'shopify') {
                    SyncBrandHistoryJob::dispatch($brand, $conn)->delay($delayAt);
                    $dispatched++;
                    continue;
                }
                for ($d = $from; $d->lessThanOrEqualTo($today); $d = $d->addDay()) {
                    SyncBrandDayJob::dispatch($brand, $conn, $d)->delay($delayAt);
                    $dispatched++;
                }
            }

            $staggerSeconds += $perBrandStagger;
        }

        return response()->json([
            'dispatched'    => $dispatched,
            'brandsSynced'  => $brandsSynced,
            'brandsSkipped' => $brandsSkipped, // active brands with no connections
            'totalBrands'   => $brands->count(),
        ], 202);
    }

    /**
     * GET /api/sync/status/export.csv
     *
     * Streams the last 30 days of sync logs as CSV. Chunked DB reads.
     */
    public function exportCsv(): StreamedResponse
    {
        $filename = 'helm-sync-log-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Started', 'Completed', 'Brand', 'Platform', 'Target date', 'Status', 'Records', 'Duration (s)', 'Error']);

            SyncLog::query()
                ->with('brand:id,name,slug')
                ->where('created_at', '>=', now()->subDays(30))
                ->orderByDesc('created_at')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        $duration = null;
                        if ($r->started_at && $r->completed_at) {
                            $duration = $r->completed_at->diffInSeconds($r->started_at);
                        }
                        fputcsv($out, [
                            $r->started_at?->toIso8601String(),
                            $r->completed_at?->toIso8601String(),
                            $r->brand?->slug ?? 'unknown',
                            $r->platform,
                            $r->target_date,
                            $r->status,
                            $r->records_processed ?? '',
                            $duration,
                            $r->error_message ?? '',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * POST /api/sync-logs/{log}/retry
     *
     * Re-dispatches a single failed SyncBrandDayJob from a sync_log row.
     * Used by the per-row Retry button on Sync health.
     */
    public function retryLog(\App\Models\SyncLog $log): JsonResponse
    {
        $brand = $log->brand;
        if (! $brand) {
            return response()->json(['message' => 'This sync log has no associated brand.'], 404);
        }

        $connection = \App\Models\PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $log->platform)
            ->first();

        if (! $connection) {
            return response()->json([
                'message' => "Brand no longer has a {$log->platform} connection. Re-add it first.",
            ], 409);
        }

        $date = CarbonImmutable::parse($log->target_date)->startOfDay();
        SyncBrandDayJob::dispatch($brand, $connection, $date);

        return response()->json([
            'queued'      => true,
            'platform'    => $log->platform,
            'target_date' => $log->target_date,
        ], 202);
    }

    /** POST /api/brands/{brand}/backfill */
    public function backfill(BackfillRequest $request, Brand $brand): JsonResponse
    {
        $data = $request->validated();
        $from = CarbonImmutable::parse($data['from'], $brand->timezone)->startOfDay();
        $to   = CarbonImmutable::parse($data['to'], $brand->timezone)->endOfDay();

        BackfillBrandRangeJob::dispatch($brand, $from, $to);

        return response()->json([
            'queued' => true,
            'from'   => $from->toDateString(),
            'to'     => $to->toDateString(),
        ], 202);
    }
}
