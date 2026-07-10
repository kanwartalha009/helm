<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Http\Resources\PlatformConnectionResource;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Brand::class);

        $brands = Brand::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('group_tag'), fn ($q, $v) => $q->where('group_tag', $v))
            ->when($request->query('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%")) // like, not ilike — Postgres-only operator 500s on MySQL (D-001; audit 2026-07-10)
            // Eager-load for the list columns: connection summary (count / platforms
            // / freshest sync) and the assigned team. Also removes the shopDomain
            // N+1 the resource previously ran once per row.
            ->with([
                'connections:id,brand_id,platform,status,last_sync_at,external_id',
                'users:id,name',
            ])
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Slug resolution order:
        //   1. Caller-provided slug, if any (already validated regex).
        //   2. Slugified name.
        // Then we append -2, -3, etc. until it's unique. The previous code
        // just slugified the name and let Postgres throw on collision.
        $base = $data['slug'] ?? '';
        if ($base === '') {
            $base = Str::slug($data['name']);
        }
        $data['slug'] = $this->uniqueSlug($base);

        $brand = Brand::create($data);

        return (new BrandResource($brand))->response()->setStatusCode(201);
    }

    /**
     * Find the next free slug derived from $base. Returns $base if it's free,
     * otherwise $base-2, $base-3, ... up to 999. We cap the loop so a runaway
     * never blocks brand creation indefinitely — if we hit 999 we throw a
     * RuntimeException and the caller surfaces it as a 500 (which is a real
     * signal that something is very wrong, not normal usage).
     */
    private function uniqueSlug(string $base): string
    {
        $base = Str::slug($base) ?: 'brand';
        if (! Brand::query()->where('slug', $base)->exists()) {
            return $base;
        }
        for ($i = 2; $i <= 999; $i++) {
            $candidate = "{$base}-{$i}";
            if (! Brand::query()->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }
        throw new \RuntimeException("Couldn't find an available slug for '{$base}' after 998 tries.");
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $brand->load('connections');

        return response()->json([
            'brand'       => new BrandResource($brand),
            'connections' => PlatformConnectionResource::collection($brand->connections),
        ]);
    }

    public function update(UpdateBrandRequest $request, Brand $brand): BrandResource
    {
        $brand->update($request->validated());
        return new BrandResource($brand);
    }

    /**
     * Hard-delete the brand and every related row. The child FKs all have
     * `cascadeOnDelete()` so platform_connections, daily_metrics, and
     * sync_logs go with it automatically. Wrapped in a transaction so a
     * partial failure leaves nothing dangling.
     *
     * If we ever need a soft-archive (preserve audit history while hiding
     * from the dashboard), add a separate endpoint — don't mix the two
     * concerns under one route.
     */
    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('delete', $brand);

        \Illuminate\Support\Facades\DB::transaction(function () use ($brand) {
            \App\Models\AuditLog::create([
                'actor_user_id' => request()->user()?->id,
                'action'        => 'brand.deleted',
                'target_type'   => 'brand',
                'target_id'     => $brand->id,
                'metadata'      => [
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                ],
                'ip'            => request()->ip(),
                'user_agent'    => request()->userAgent(),
            ]);
            $brand->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * GET /api/brands/{brand}/metrics
     *
     * Returns every daily_metrics row for the brand plus pre-rolled tiles
     * for today / yesterday / last-7-days / last-30-days. Drives the Overview
     * tab on the brand detail page so a freshly synced store shows real
     * numbers without an additional client-side reduction.
     */
    /**
     * GET /api/brands/{brand}/users — users assigned to this brand via
     * brand_user_access. Admin/manager only (BrandPolicy::update).
     */
    public function users(Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $users = $brand->users()->orderBy('name')->get();

        return response()->json([
            'userIds' => $users->pluck('id')->all(),
            'users'   => $users->map(fn (User $u) => [
                'id'     => $u->id,
                'name'   => $u->name,
                'email'  => $u->email,
                'role'   => $u->role,
                'status' => $u->status,
            ])->values()->all(),
        ]);
    }

    /**
     * PUT /api/brands/{brand}/users — replace the brand's assigned users with the
     * given set. Writes brand_access.granted / brand_access.revoked audit rows for
     * the diff (spec §08). Admin/manager only.
     */
    public function syncUsers(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $data = $request->validate([
            'user_ids'   => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $newIds     = array_values(array_unique(array_map('intval', $data['user_ids'])));
        $currentIds = $brand->users()->pluck('users.id')->all();
        $added      = array_values(array_diff($newIds, $currentIds));
        $removed    = array_values(array_diff($currentIds, $newIds));

        $payload = [];
        foreach ($newIds as $uid) {
            $payload[$uid] = ['granted_by_user_id' => $request->user()?->id];
        }
        $brand->users()->sync($payload);

        foreach ($added as $uid) {
            AuditLog::create([
                'actor_user_id' => $request->user()?->id,
                'action'        => 'brand_access.granted',
                'target_type'   => 'brand',
                'target_id'     => $brand->id,
                'metadata'      => ['user_id' => $uid],
                'ip'            => $request->ip(),
                'user_agent'    => $request->userAgent(),
            ]);
        }
        foreach ($removed as $uid) {
            AuditLog::create([
                'actor_user_id' => $request->user()?->id,
                'action'        => 'brand_access.revoked',
                'target_type'   => 'brand',
                'target_id'     => $brand->id,
                'metadata'      => ['user_id' => $uid],
                'ip'            => $request->ip(),
                'user_agent'    => $request->userAgent(),
            ]);
        }

        return response()->json(['userIds' => $newIds]);
    }

    /**
     * Rolled-up tiles + a bounded daily series for the brand detail page.
     *
     * Tiles are computed as SQL aggregates (constant memory regardless of how
     * much history the brand has); the daily drill-in is windowed — default 90
     * days, `?days=` up to 365 — instead of loading every daily_metrics row
     * into PHP (audit 2026-07-10, layer: database). Date bounds use half-open
     * ranges [day, day+1) on the raw column so the (brand_id, date, platform)
     * index is used on MySQL and the same comparison works on sqlite in tests.
     */
    public function metrics(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $tz        = $brand->timezone ?: 'UTC';
        $today     = CarbonImmutable::now($tz)->startOfDay();
        $yesterday = $today->subDay();
        $l7Start   = $today->subDays(6);   // inclusive 7-day window: today + 6 back
        $l30Start  = $today->subDays(29);
        $tomorrow  = $today->addDay();

        $windowDays = (int) $request->query('days', '90');
        $windowDays = max(1, min(365, $windowDays));

        $tile = static function (string $label, array $agg, bool $forceIncomplete = false): array {
            return [
                'label'       => $label,
                'revenue'     => round((float) ($agg['revenue'] ?? 0), 2),
                'netSales'    => round((float) ($agg['net_sales'] ?? 0), 2),
                'totalSales'  => round((float) ($agg['total_sales'] ?? 0), 2),
                'orders'      => (int) ($agg['orders'] ?? 0),
                'refunds'     => round((float) ($agg['refunds'] ?? 0), 2),
                'days'        => (int) ($agg['days'] ?? 0),
                'isComplete'  => ! $forceIncomplete && (int) ($agg['incomplete_days'] ?? 0) === 0,
            ];
        };

        $aggregate = function (?string $from, ?string $toExclusive) use ($brand): array {
            $q = DailyMetric::query()
                ->where('brand_id', $brand->id)
                ->where('platform', 'shopify');
            if ($from !== null) {
                $q->where('date', '>=', $from);
            }
            if ($toExclusive !== null) {
                $q->where('date', '<', $toExclusive);
            }

            $row = $q->selectRaw(
                'SUM(COALESCE(revenue, 0))                                   as revenue,'
                . 'SUM(COALESCE(net_sales, 0))                               as net_sales,'
                . 'SUM(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)) as total_sales,'
                . 'SUM(COALESCE(orders, 0))                                  as orders,'
                . 'SUM(COALESCE(refunds_amount, 0))                          as refunds,'
                . 'COUNT(*)                                                  as days,'
                . 'SUM(CASE WHEN is_complete THEN 0 ELSE 1 END)              as incomplete_days'
            )->first();

            return $row !== null ? (array) $row->getAttributes() : [];
        };

        $todayAgg     = $aggregate($today->toDateString(), $tomorrow->toDateString());
        $yesterdayAgg = $aggregate($yesterday->toDateString(), $today->toDateString());
        $last7Agg     = $aggregate($l7Start->toDateString(), $tomorrow->toDateString());
        $last30Agg    = $aggregate($l30Start->toDateString(), $tomorrow->toDateString());
        $allAgg       = $aggregate(null, null);

        $todayTile              = $tile('Today', $todayAgg, forceIncomplete: true); // today is always partial
        $yesterdayTile          = $tile('Yesterday', $yesterdayAgg);
        $yesterdayTile['days']  = max(1, $yesterdayTile['days']);
        $last7Tile              = $tile('Last 7 days', $last7Agg);
        $last30Tile             = $tile('Last 30 days', $last30Agg);
        $allTile                = $tile('All time', $allAgg);

        // Daily drill-in: one row per date, Shopify sales + blended ad spend,
        // newest first — same shape as before, now bounded to the window.
        $dailyStart = $today->subDays($windowDays - 1)->toDateString();
        $rows = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('date', '>=', $dailyStart)
            ->where('date', '<', $tomorrow->toDateString())
            ->orderByDesc('date')
            ->get();

        $daily     = [];
        $idxByDate = [];
        foreach ($rows as $r) {
            $d = $r->date->toDateString();
            if (! isset($idxByDate[$d])) {
                $idxByDate[$d] = count($daily);
                $daily[] = [
                    'date'       => $d,
                    'netSales'   => null,
                    'totalSales' => null,
                    'orders'     => null,
                    'refunds'    => null,
                    'spend'      => null,
                    'roas'       => null,
                    'currency'   => $brand->base_currency,
                    'isComplete' => false,
                    'pulledAt'   => null,
                ];
            }
            $i = $idxByDate[$d];

            if ($r->platform === 'shopify') {
                $daily[$i]['netSales']   = $r->net_sales !== null ? (float) $r->net_sales : null;
                // Total revenue = total_sales + refunds (Bosco 2026-06-25); the
                // Refunds column still shows the refund amount on its own.
                $daily[$i]['totalSales'] = $r->total_sales !== null
                    ? (float) $r->total_sales + (float) ($r->refunds_amount ?? 0)
                    : null;
                $daily[$i]['orders']     = $r->orders;
                $daily[$i]['refunds']    = $r->refunds_amount !== null ? (float) $r->refunds_amount : null;
                $daily[$i]['currency']   = $r->currency;
                $daily[$i]['isComplete'] = (bool) $r->is_complete;
                $daily[$i]['pulledAt']   = $r->pulled_at?->toIso8601String();
            } elseif ($r->spend !== null) {
                $daily[$i]['spend'] = round((float) ($daily[$i]['spend'] ?? 0) + (float) $r->spend, 2);
            }
        }

        // Blended ROAS per day = Total revenue ÷ blended spend (native currency).
        foreach ($daily as &$dayRow) {
            if ($dayRow['spend'] !== null && $dayRow['spend'] > 0 && $dayRow['totalSales'] !== null) {
                $dayRow['roas'] = round($dayRow['totalSales'] / $dayRow['spend'], 2);
            }
        }
        unset($dayRow);

        return response()->json([
            'currency'   => $brand->base_currency,
            'timezone'   => $brand->timezone,
            'windowDays' => $windowDays,
            'tiles'      => [
                'today'     => $todayTile,
                'yesterday' => $yesterdayTile,
                'last7'     => $last7Tile,
                'last30'    => $last30Tile,
                'allTime'   => $allTile,
            ],
            'daily'      => $daily,
        ]);
    }
}
