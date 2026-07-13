<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshBrandInventoryJob;
use App\Models\BackfillRun;
use App\Models\Brand;
use App\Services\Aggregation\InventoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Inventory Intelligence — per-brand product table (stock × ad spend × sessions). Brand scoped
 * behind the access.brand middleware + BrandPolicy, so a user only sees a brand they're assigned to.
 *
 *   GET  /api/brands/{brand}/inventory?period=last7|last30|mtd|custom&from=&to=
 *   POST /api/brands/{brand}/inventory/sync    — refresh stock/sales/spend/sessions (queued)
 *   GET  /api/brands/{brand}/inventory/sync    — poll that refresh
 */
class InventoryController extends Controller
{
    /** How far back a manual refresh re-pulls. Short on purpose — see RefreshBrandInventoryJob. */
    private const REFRESH_DAYS = 7;

    public function __construct(private readonly InventoryQuery $query) {}

    public function show(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'period' => ['nullable', 'in:last7,last30,mtd,custom'],
            'from'   => ['nullable', 'date_format:Y-m-d'],
            'to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($this->query->run($brand, $params));
    }

    /**
     * Queue a refresh of everything this page reads. Returns immediately — the pull takes tens of
     * seconds to minutes, so it runs on the queue and the UI polls `syncStatus` below.
     */
    public function sync(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        // One refresh at a time per brand. A second click while one is in flight returns the SAME
        // run rather than 409-ing: from the operator's side they clicked "sync" and a sync is
        // happening, so telling them it failed would be a lie.
        $active = BackfillRun::query()
            ->where('brand_id', $brand->id)
            ->where('dataset', RefreshBrandInventoryJob::DATASET)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($active !== null) {
            return response()->json($this->payload($active), 202);
        }

        $tz  = $brand->timezone ?: 'UTC';
        $run = BackfillRun::create([
            'brand_id'             => $brand->id,
            'dataset'              => RefreshBrandInventoryJob::DATASET,
            'status'               => 'queued',
            'window_start'         => CarbonImmutable::now($tz)->subDays(self::REFRESH_DAYS)->toDateString(),
            'triggered_by_user_id' => Auth::id(),
            'message'              => 'Queued…',
        ]);

        RefreshBrandInventoryJob::dispatch($brand, (int) $run->id, self::REFRESH_DAYS);

        return response()->json($this->payload($run->fresh()), 202);
    }

    /** Poll the most recent refresh for this brand. `null` run = never synced from this button. */
    public function syncStatus(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $run = BackfillRun::query()
            ->where('brand_id', $brand->id)
            ->where('dataset', RefreshBrandInventoryJob::DATASET)
            ->latest('id')
            ->first();

        return response()->json($run === null ? ['run' => null] : $this->payload($run));
    }

    /** @return array<string, mixed> */
    private function payload(BackfillRun $run): array
    {
        return [
            'run' => [
                'id'         => (int) $run->id,
                'status'     => (string) $run->status,          // queued | running | done | failed
                // The step line the job writes BEFORE each step ("2/5 · Sessions"), so the UI can
                // name what is syncing right now instead of showing an anonymous spinner.
                'message'    => $run->message,
                'startedAt'  => $run->started_at?->toIso8601String(),
                'finishedAt' => $run->finished_at?->toIso8601String(),
            ],
        ];
    }
}
