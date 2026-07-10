<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillBrandDatasetJob;
use App\Jobs\BackfillBrandRangeJob;
use App\Models\BackfillRun;
use App\Models\Brand;
use App\Models\SyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Data coverage + manual backfill for onboarding (2026-07-10). A freshly
 * connected brand only accrues data going forward from the daily sync; this
 * surface shows which datasets are missing history against the 12-month
 * target and lets an operator pull it once. Buttons only render for gaps —
 * a fully covered brand shows nothing (the SPA hides the card entirely).
 *
 * Datasets:
 *  - history   — daily_metrics for every connected platform. Backfills via
 *                the existing per-day fan-out (BackfillBrandRangeJob →
 *                SyncBrandDayJob), visible on Sync health as it drains.
 *  - campaigns — ad_campaign_daily_metrics   (ads:backfill-campaigns)
 *  - creatives — ad_creative_daily           (meta/tiktok:backfill-creatives)
 *  - commerce  — commerce_daily_metrics      (shopify:backfill-commerce)
 */
class BrandDataCoverageController extends Controller
{
    /** Target history depth for onboarding backfills (ratified 2026-07-10). */
    private const TARGET_MONTHS = 12;

    /** Days of slack before an earliest-row date counts as a gap. */
    private const GRACE_DAYS = 7;

    public function index(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $tz          = $brand->timezone ?: 'UTC';
        $today       = CarbonImmutable::now($tz)->startOfDay();
        $targetStart = $today->subMonths(self::TARGET_MONTHS);

        $connected = $brand->connections()->where('status', 'active')->pluck('platform')->all();
        $adPlatforms = array_values(array_intersect($connected, ['meta', 'google', 'tiktok']));

        // Latest run per tracked dataset, one query.
        $lastRuns = BackfillRun::query()
            ->where('brand_id', $brand->id)
            ->orderByDesc('id')
            ->get()
            ->unique('dataset')
            ->keyBy('dataset');

        $datasets = [];

        // --- history (daily_metrics, per connected platform) -----------------
        $earliestByPlatform = DB::table('daily_metrics')
            ->where('brand_id', $brand->id)
            ->groupBy('platform')
            ->selectRaw('platform, MIN(date) as earliest, MAX(date) as latest')
            ->get()
            ->keyBy('platform');

        $historyPlatforms = [];
        $historyGap       = false;
        foreach ($connected as $platform) {
            $row      = $earliestByPlatform[$platform] ?? null;
            $earliest = $row?->earliest ? CarbonImmutable::parse((string) $row->earliest)->toDateString() : null;
            $gap      = $earliest === null || $earliest > $targetStart->addDays(self::GRACE_DAYS)->toDateString();
            $historyGap = $historyGap || $gap;
            $historyPlatforms[] = [
                'platform' => $platform,
                'earliest' => $earliest,
                'latest'   => $row?->latest ? CarbonImmutable::parse((string) $row->latest)->toDateString() : null,
                'gap'      => $gap,
            ];
        }

        // A history backfill in flight = queued/running sync_logs for dates
        // older than the recent sync window (the daily cron never dispatches
        // days older than 7 back, so anything older is a backfill draining).
        $historyRunning = SyncLog::query()
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['queued', 'running'])
            ->where('target_date', '<', $today->subDays(8)->toDateString())
            ->exists();

        $datasets[] = [
            'key'           => 'history',
            'label'         => 'Daily revenue & spend history',
            'relevant'      => $connected !== [],
            'needsBackfill' => $historyGap,
            'running'       => $historyRunning,
            'platforms'     => $historyPlatforms,
            'lastRun'       => null, // tracked on Sync health, not backfill_runs
        ];

        // --- tracked datasets -------------------------------------------------
        $tracked = [
            'campaigns' => [
                'label'    => 'Campaign-level ad history',
                'relevant' => $adPlatforms !== [],
                'table'    => 'ad_campaign_daily_metrics',
            ],
            'creatives' => [
                'label'    => 'Creative-level ad history',
                'relevant' => array_intersect($adPlatforms, ['meta', 'tiktok']) !== [],
                'table'    => 'ad_creative_daily',
            ],
            'commerce'  => [
                'label'    => 'Product / country / category revenue',
                'relevant' => in_array('shopify', $connected, true),
                'table'    => 'commerce_daily_metrics',
            ],
        ];

        foreach ($tracked as $key => $meta) {
            $earliest = null;
            $latest   = null;
            if ($meta['relevant']) {
                $row = DB::table($meta['table'])
                    ->where('brand_id', $brand->id)
                    ->selectRaw('MIN(date) as earliest, MAX(date) as latest')
                    ->first();
                $earliest = $row?->earliest ? CarbonImmutable::parse((string) $row->earliest)->toDateString() : null;
                $latest   = $row?->latest ? CarbonImmutable::parse((string) $row->latest)->toDateString() : null;
            }

            $lastRun = $lastRuns[$key] ?? null;

            $datasets[] = [
                'key'           => $key,
                'label'         => $meta['label'],
                'relevant'      => $meta['relevant'],
                'needsBackfill' => $meta['relevant']
                    && ($earliest === null || $earliest > $targetStart->addDays(self::GRACE_DAYS)->toDateString()),
                'running'       => $lastRun !== null && in_array($lastRun->status, ['queued', 'running'], true),
                'platforms'     => [['platform' => $key === 'commerce' ? 'shopify' : implode('+', $adPlatforms), 'earliest' => $earliest, 'latest' => $latest, 'gap' => $earliest === null]],
                'lastRun'       => $lastRun ? [
                    'status'     => $lastRun->status,
                    'startedAt'  => $lastRun->started_at?->toIso8601String(),
                    'finishedAt' => $lastRun->finished_at?->toIso8601String(),
                    'message'    => $lastRun->message,
                ] : null,
            ];
        }

        return response()->json([
            'targetStart'   => $targetStart->toDateString(),
            'targetMonths'  => self::TARGET_MONTHS,
            'datasets'      => $datasets,
            'anyGap'        => collect($datasets)->contains(fn ($d) => $d['relevant'] && ($d['needsBackfill'] || $d['running'])),
        ]);
    }

    /** POST /api/brands/{brand}/backfill-dataset {dataset} — admin/manager only (route gate). */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'dataset' => ['required', 'in:history,campaigns,creatives,commerce'],
        ]);
        $dataset = $data['dataset'];

        $tz          = $brand->timezone ?: 'UTC';
        $today       = CarbonImmutable::now($tz)->startOfDay();
        $targetStart = $today->subMonths(self::TARGET_MONTHS);

        if ($dataset === 'history') {
            // Existing per-day fan-out; progress lives on Sync health.
            BackfillBrandRangeJob::dispatch($brand, $targetStart, $today->subDay());

            return response()->json([
                'dataset' => 'history',
                'message' => 'History backfill queued — one job per day per connection. Track it on Sync health.',
            ], 202);
        }

        // One run at a time per (brand, dataset).
        $active = BackfillRun::query()
            ->where('brand_id', $brand->id)
            ->where('dataset', $dataset)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($active) {
            return response()->json(['message' => 'A backfill for this dataset is already running.'], 409);
        }

        $run = BackfillRun::create([
            'brand_id'             => $brand->id,
            'dataset'              => $dataset,
            'status'               => 'queued',
            'window_start'         => $targetStart->toDateString(),
            'triggered_by_user_id' => Auth::id(),
        ]);

        BackfillBrandDatasetJob::dispatch($brand, $dataset, $run->id);

        return response()->json(['dataset' => $dataset, 'runId' => $run->id], 202);
    }
}
