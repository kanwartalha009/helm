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
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncStatusController extends Controller
{
    /**
     * Idempotency window. A second "Sync now" click within this window for
     * a brand that already has queued/running work is rejected (per-brand
     * trigger) or skipped (master triggerAll / cron).
     *
     * Tuned to cover the worst-case wall time of a single Shopify history
     * scan (15m × 2 attempts = 30m) plus a couple of minutes' worth of
     * Horizon backoff slack. Past 30 minutes a queued/running row is
     * treated as orphaned (Horizon crashed, queue stuck) and a fresh
     * dispatch is allowed.
     */
    private const IDEMPOTENCY_WINDOW_MINUTES = 30;

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
     * Idempotent: if any sync_log row is already queued/running for this
     * brand within the last IDEMPOTENCY_WINDOW_MINUTES, returns 409 with a
     * payload describing the in-flight work — no new jobs are dispatched.
     *
     * The nightly cron still owns the canonical 7-day rolling backfill —
     * see RunDailySyncCommand. That's where late-refund attribution settles.
     */
    public function trigger(Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $existing = $this->pendingLogsForBrand($brand->id);
        if ($existing->isNotEmpty()) {
            return response()->json([
                'queued'    => false,
                'reason'    => 'already_in_progress',
                'message'   => "A sync is already in progress for {$brand->name}. Wait for it to finish before queueing another.",
                'inFlight'  => $existing->map(fn ($l) => [
                    'id'         => $l->id,
                    'platform'   => $l->platform,
                    'status'     => $l->status,
                    'started_at' => $l->started_at?->toIso8601String(),
                    'created_at' => $l->created_at?->toIso8601String(),
                ])->all(),
            ], 409);
        }

        $today = CarbonImmutable::now($brand->timezone)->startOfDay();
        $from  = $today->subDays(6);

        $connections = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('status', '!=', 'paused')
            ->get();

        $dispatched = $this->dispatchForBrand($brand, $connections, $today, $from);

        return response()->json([
            'queued'     => true,
            'dispatched' => $dispatched,
            'mode'       => 'history-for-shopify+7d-for-ads',
            'from'       => $from->toDateString(),
            'to'         => $today->toDateString(),
            'platforms'  => $connections->pluck('platform')->all(),
        ], 202);
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
     * Brands with an in-flight sync (queued/running within the idempotency
     * window) are silently skipped and counted in `brandsAlreadyRunning`
     * rather than 409'ing the whole call. The dashboard click is portfolio-
     * wide — partial success is the right default.
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

        // Per-brand pending lookup — single query across the brand set so we
        // don't issue 100+ lookups during a portfolio fan-out.
        $pendingByBrand = $this->pendingLogsForBrandIds($brandIds)->groupBy('brand_id');

        $dispatched           = 0;
        $brandsSynced         = 0;
        $brandsSkipped        = 0; // active brands with no connections
        $brandsAlreadyRunning = 0; // active brands with an in-flight sync

        // Prioritise the brands that need it most: sync the stalest (or
        // never-synced) Shopify connections first, so if anything interrupts
        // the run, the brands with the most-missing data are already done.
        $sortedBrands = $brands->sortBy(function (Brand $brand) use ($connectionsByBrand): int {
            $shopify = ($connectionsByBrand->get($brand->id) ?? collect())->firstWhere('platform', 'shopify');
            return $shopify?->last_sync_at?->getTimestamp() ?? 0; // never-synced sorts first
        })->values();

        foreach ($sortedBrands as $brand) {
            if ($pendingByBrand->has($brand->id)) {
                $brandsAlreadyRunning++;
                continue;
            }

            $connections = $connectionsByBrand->get($brand->id) ?? collect();
            if ($connections->isEmpty()) {
                $brandsSkipped++;
                continue;
            }

            $brandsSynced++;
            $today = CarbonImmutable::now($brand->timezone)->startOfDay();

            // The master sync is a dashboard REFRESH, not a first-import. Bound
            // Shopify to a recent window (35 days — covers yesterday, day-before,
            // L7d, L30, MTD) instead of the all-time scan, and ads to 3 days.
            // That's the difference between ~5s and ~78s on a large store like
            // Meller, and it stops the fan-out from exhausting the box's threads
            // (the getaddrinfo failures). No dispatch stagger — Horizon worker
            // concurrency already caps how many run at once; the old 30s/brand
            // stagger just made the last brand wait ~40 minutes.
            $shopifySince = $today->subDays(34);
            $adsFrom      = $today->subDays(2);

            $dispatched += $this->dispatchForBrand($brand, $connections, $today, $adsFrom, null, $shopifySince);
        }

        return response()->json([
            'dispatched'           => $dispatched,
            'brandsSynced'         => $brandsSynced,
            'brandsSkipped'        => $brandsSkipped,
            'brandsAlreadyRunning' => $brandsAlreadyRunning,
            'totalBrands'          => $brands->count(),
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
     * Used by the per-row Retry button on Sync health. Writes a fresh
     * `queued` row for the retry so it shows up in the queue tile and the
     * audit chain stays clean (the original failed row keeps its error
     * message; the retry row tells a new story).
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

        $queued = SyncLog::create([
            'brand_id'    => $brand->id,
            'platform'    => $log->platform,
            'target_date' => $date->toDateString(),
            'status'      => 'queued',
            'started_at'  => null,
        ]);

        SyncBrandDayJob::dispatch($brand, $connection, $date, $queued->id);

        return response()->json([
            'queued'      => true,
            'logId'       => $queued->id,
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

    // ---------- internal helpers ------------------------------------------

    /**
     * Dispatch all sync jobs for one (brand × connections) tuple and write
     * a `queued` sync_logs row for each job at dispatch time so the Sync
     * health page shows pending work immediately.
     *
     * Shopify → one SyncBrandHistoryJob (today as target_date — the row
     * spans many days but the schema NOT-NULLs date).
     * Ads      → 7 × SyncBrandDayJob (today + 6 prior).
     *
     * @param  \Illuminate\Support\Collection<int, PlatformConnection>  $connections
     * @return int Number of jobs dispatched.
     */
    private function dispatchForBrand(
        Brand $brand,
        Collection $connections,
        CarbonImmutable $today,
        CarbonImmutable $from,
        ?\Illuminate\Support\Carbon $delayAt = null,
        ?CarbonImmutable $shopifySince = null,
    ): int {
        $dispatched = 0;

        foreach ($connections as $conn) {
            if ($conn->platform === 'shopify') {
                $log = SyncLog::create([
                    'brand_id'    => $brand->id,
                    'platform'    => 'shopify',
                    'target_date' => $today->toDateString(),
                    'status'      => 'queued',
                    'started_at'  => null,
                ]);
                $pending = SyncBrandHistoryJob::dispatch($brand, $conn, $shopifySince, $log->id);
                if ($delayAt !== null) {
                    $pending->delay($delayAt);
                }
                $dispatched++;
                continue;
            }

            // Ad platform — fan out one day at a time across the window, one queued sync_logs
            // row per day.
            //
            // NEWEST FIRST. This used to walk $from → $today, which queued the OLDEST day first
            // and left today's spend — the number on the dashboard — until last. On a queue that
            // is FIFO, that is the worst possible order: the operator waits for six days of
            // history they aren't looking at before the figure they clicked "sync" for appears.
            for ($d = $today; $d->greaterThanOrEqualTo($from); $d = $d->subDay()) {
                $log = SyncLog::create([
                    'brand_id'    => $brand->id,
                    'platform'    => $conn->platform,
                    'target_date' => $d->toDateString(),
                    'status'      => 'queued',
                    'started_at'  => null,
                ]);
                $pending = SyncBrandDayJob::dispatch($brand, $conn, $d, $log->id);
                if ($delayAt !== null) {
                    $pending->delay($delayAt);
                }
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Returns any sync_logs in queued/running status for this brand within
     * the idempotency window. Used by `trigger()` (returns 409) and
     * indirectly by `triggerAll()` (counts as "alreadyRunning").
     *
     * @return \Illuminate\Support\Collection<int, SyncLog>
     */
    private function pendingLogsForBrand(int $brandId): Collection
    {
        return SyncLog::query()
            ->where('brand_id', $brandId)
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(self::IDEMPOTENCY_WINDOW_MINUTES))
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Bulk variant of pendingLogsForBrand for the master fan-out — one query
     * across the brand set instead of N.
     *
     * @param  array<int, int>  $brandIds
     * @return \Illuminate\Support\Collection<int, SyncLog>
     */
    private function pendingLogsForBrandIds(array $brandIds): Collection
    {
        return SyncLog::query()
            ->whereIn('brand_id', $brandIds)
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(self::IDEMPOTENCY_WINDOW_MINUTES))
            ->get();
    }
}
