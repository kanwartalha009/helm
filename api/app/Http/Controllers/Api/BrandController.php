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
            ->when($request->query('search'), fn ($q, $v) => $q->where('name', 'ilike', "%{$v}%"))
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

    public function metrics(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $tz        = $brand->timezone ?: 'UTC';
        $today     = CarbonImmutable::now($tz)->startOfDay();
        $yesterday = $today->subDay();
        $l7Start   = $today->subDays(6);   // inclusive 7-day window: today + 6 back
        $l30Start  = $today->subDays(29);

        // One sweep over every metric row — cheaper than 4 separate aggregates.
        $rows = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->orderByDesc('date')
            ->get();

        $todayKey     = $today->toDateString();
        $yesterdayKey = $yesterday->toDateString();
        $l7StartKey   = $l7Start->toDateString();
        $l30StartKey  = $l30Start->toDateString();

        $tile = static function (string $label) {
            return [
                'label'       => $label,
                'revenue'     => 0.0,
                'netSales'    => 0.0,
                'totalSales'  => 0.0,
                'orders'      => 0,
                'refunds'     => 0.0,
                'days'        => 0,
                'isComplete'  => true,
            ];
        };

        $todayTile     = $tile('Today');
        $yesterdayTile = $tile('Yesterday');
        $last7Tile     = $tile('Last 7 days');
        $last30Tile    = $tile('Last 30 days');
        $allTile       = $tile('All time');

        $todayTile['isComplete']     = false; // today is always partial
        $yesterdayTile['days']       = 1;

        foreach ($rows as $r) {
            // We only aggregate Shopify rows here — ad platforms add spend later.
            if ($r->platform !== 'shopify') {
                continue;
            }

            $d = $r->date->toDateString();
            $rev = (float) ($r->revenue ?? 0);   // Total revenue (gross, before returns) — the toggle option
            $net = (float) ($r->net_sales ?? 0);   // Net sales (hidden in UI, still aggregated)
            // Total revenue = Shopify Total sales WITH refunds added back (Bosco
            // 2026-06-25): total_sales already nets returns out, so we add them
            // back. The Refunds figure ($ref) is still surfaced on its own.
            $ref = (float) ($r->refunds_amount ?? 0);
            $tot = (float) ($r->total_sales ?? 0) + $ref;
            $ord = (int)   ($r->orders ?? 0);

            $allTile['revenue'] += $rev;
            $allTile['netSales']   += $net;
            $allTile['totalSales'] += $tot;
            $allTile['orders']     += $ord;
            $allTile['refunds']    += $ref;
            $allTile['days']       += 1;
            if (! $r->is_complete) {
                $allTile['isComplete'] = false;
            }

            if ($d === $todayKey) {
                $todayTile['revenue'] = $rev;
                $todayTile['netSales']   = $net;
                $todayTile['totalSales'] = $tot;
                $todayTile['orders']     = $ord;
                $todayTile['refunds']    = $ref;
                $todayTile['days']       = 1;
                // isComplete already false above.
            }
            if ($d === $yesterdayKey) {
                $yesterdayTile['revenue'] = $rev;
                $yesterdayTile['netSales']   = $net;
                $yesterdayTile['totalSales'] = $tot;
                $yesterdayTile['orders']     = $ord;
                $yesterdayTile['refunds']    = $ref;
                $yesterdayTile['isComplete'] = (bool) $r->is_complete;
            }
            if ($d >= $l7StartKey && $d <= $todayKey) {
                $last7Tile['revenue'] += $rev;
                $last7Tile['netSales']   += $net;
                $last7Tile['totalSales'] += $tot;
                $last7Tile['orders']     += $ord;
                $last7Tile['refunds']    += $ref;
                $last7Tile['days']       += 1;
                if (! $r->is_complete) {
                    $last7Tile['isComplete'] = false;
                }
            }
            if ($d >= $l30StartKey && $d <= $todayKey) {
                $last30Tile['revenue'] += $rev;
                $last30Tile['netSales']   += $net;
                $last30Tile['totalSales'] += $tot;
                $last30Tile['orders']     += $ord;
                $last30Tile['refunds']    += $ref;
                $last30Tile['days']       += 1;
                if (! $r->is_complete) {
                    $last30Tile['isComplete'] = false;
                }
            }
        }

        // One row per date. Shopify carries sales (net sales, Total revenue =
        // Shopify total_sales, orders, refunds); the polymorphic Meta/Google/
        // TikTok rows carry spend, which we blend into one figure plus a Blended
        // ROAS (Total revenue ÷ spend) in the brand's native currency. $rows is
        // date-desc, so first-seen order keeps the table newest-first.
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
            'currency' => $brand->base_currency,
            'timezone' => $brand->timezone,
            'tiles'    => [
                'today'     => $todayTile,
                'yesterday' => $yesterdayTile,
                'last7'     => $last7Tile,
                'last30'    => $last30Tile,
                'allTime'   => $allTile,
            ],
            'daily'    => $daily,
        ]);
    }
}
