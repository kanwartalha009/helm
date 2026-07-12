<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillBrandDatasetJob;
use App\Models\BackfillRun;
use App\Models\Brand;
use App\Services\PlatformCredentialService;
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
 *  - all       — every dataset below in ONE tracked job (the default button).
 *  - history   — daily_metrics for every connected platform via the RANGED
 *                commands (shopify:backfill-sales, ads:backfill-spend,
 *                tiktok:backfill-daily) — one job, not per-day fan-out.
 *  - campaigns — ad_campaign_daily_metrics + ad_set_daily_metrics
 *                (ads:backfill-campaigns + ads:backfill-adsets)
 *  - creatives — ad_creative_daily           (meta/tiktok:backfill-creatives)
 *  - commerce  — commerce_daily_metrics      (shopify:backfill-commerce)
 */
class BrandDataCoverageController extends Controller
{
    /** Target history depth for onboarding backfills (ratified 2026-07-10). */
    private const TARGET_MONTHS = 12;

    /** Days of slack before an earliest-row date counts as a gap. */
    private const GRACE_DAYS = 7;

    public function index(Brand $brand, PlatformCredentialService $credentials): JsonResponse
    {
        $this->authorize('view', $brand);

        // Klaviyo is not a platform_connection — a brand "has email" when it has
        // its own Klaviyo key (GO-1.1). Brands without one never see the card.
        $hasKlaviyo  = $credentials->has('klaviyo', 'private_key', (int) $brand->id);
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

        // Every dataset — history included — is a single tracked run since
        // 2026-07-10 (no more per-day fan-out). An active 'all' run counts as
        // running for every dataset.
        $allRun     = $lastRuns['all'] ?? null;
        $allActive  = $allRun !== null && in_array($allRun->status, ['queued', 'running'], true);
        $runPayload = fn ($run) => $run ? [
            'status'     => $run->status,
            'startedAt'  => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'message'    => $run->message,
        ] : null;

        $historyRun = $lastRuns['history'] ?? null;
        $datasets[] = [
            'key'           => 'history',
            'label'         => 'Daily revenue & spend history',
            'relevant'      => $connected !== [],
            'needsBackfill' => $historyGap,
            'running'       => $allActive || ($historyRun !== null && in_array($historyRun->status, ['queued', 'running'], true)),
            'platforms'     => $historyPlatforms,
            'lastRun'       => $runPayload($historyRun ?? ($allRun && $allRun->status !== 'queued' ? $allRun : null)),
        ];

        // --- tracked datasets -------------------------------------------------
        // 'tables' is the set of grains a dataset must ALL cover to count as
        // complete. campaigns spans two (campaign + ad-set, spec §4): coverage is
        // the window covered by BOTH — earliest is the later of their floors, and
        // null (a gap) if either grain is empty. So the ~80 brands that already
        // backfilled campaigns but have an empty ad_set_daily_metrics correctly
        // show the campaigns card as needing a backfill.
        $tracked = [
            'campaigns' => [
                'label'    => 'Campaign & ad-set ad history',
                'relevant' => $adPlatforms !== [],
                'tables'   => ['ad_campaign_daily_metrics', 'ad_set_daily_metrics'],
            ],
            'creatives' => [
                'label'    => 'Creative-level ad history',
                'relevant' => array_intersect($adPlatforms, ['meta', 'tiktok']) !== [],
                'tables'   => ['ad_creative_daily'],
            ],
            'commerce'  => [
                'label'    => 'Product / country / category revenue',
                'relevant' => in_array('shopify', $connected, true),
                'tables'   => ['commerce_daily_metrics'],
            ],
            'email'     => [
                'label'    => 'Email revenue (Klaviyo)',
                'relevant' => $hasKlaviyo,
                'tables'   => ['email_daily_metrics'],
            ],
        ];

        foreach ($tracked as $key => $meta) {
            $earliest = null;
            $latest   = null;
            if ($meta['relevant']) {
                // Intersection window across the dataset's grains: earliest = the
                // MAX of per-table MINs (the limiting grain), latest = the MIN of
                // per-table MAXs. If any required grain has no rows, earliest stays
                // null → the dataset reads as a gap.
                $mins = [];
                $maxes = [];
                foreach ($meta['tables'] as $table) {
                    $row = DB::table($table)
                        ->where('brand_id', $brand->id)
                        ->selectRaw('MIN(date) as earliest, MAX(date) as latest')
                        ->first();
                    $mins[]  = $row?->earliest ? CarbonImmutable::parse((string) $row->earliest)->toDateString() : null;
                    $maxes[] = $row?->latest ? CarbonImmutable::parse((string) $row->latest)->toDateString() : null;
                }
                if (! in_array(null, $mins, true)) {
                    $earliest = max($mins);   // later floor = the grain that reaches back least far
                    $latest   = min($maxes);  // trailing edge covered by every grain
                }
            }

            $lastRun = $lastRuns[$key] ?? null;

            $datasets[] = [
                'key'           => $key,
                'label'         => $meta['label'],
                'relevant'      => $meta['relevant'],
                'needsBackfill' => $meta['relevant']
                    && ($earliest === null || $earliest > $targetStart->addDays(self::GRACE_DAYS)->toDateString()),
                'running'       => $allActive || ($lastRun !== null && in_array($lastRun->status, ['queued', 'running'], true)),
                'platforms'     => [['platform' => match ($key) {
                    'commerce' => 'shopify',
                    'email'    => 'klaviyo',
                    default    => implode('+', $adPlatforms),
                }, 'earliest' => $earliest, 'latest' => $latest, 'gap' => $earliest === null]],
                'lastRun'       => $runPayload($lastRun ?? ($allRun && $allRun->status !== 'queued' ? $allRun : null)),
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
            'dataset' => ['required', 'in:all,history,campaigns,creatives,commerce,email'],
        ]);
        $dataset = $data['dataset'];

        $tz          = $brand->timezone ?: 'UTC';
        $today       = CarbonImmutable::now($tz)->startOfDay();
        $targetStart = $today->subMonths(self::TARGET_MONTHS);

        // One run at a time: 'all' conflicts with anything; a specific dataset
        // conflicts with itself or an active 'all'.
        $active = BackfillRun::query()
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['queued', 'running'])
            ->when($dataset !== 'all', fn ($q) => $q->whereIn('dataset', [$dataset, 'all']))
            ->exists();
        if ($active) {
            return response()->json(['message' => 'A backfill for this brand is already running.'], 409);
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
