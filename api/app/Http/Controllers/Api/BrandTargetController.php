<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandTarget;
use App\Services\Rules\Pacing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Monthly targets + pacing (GO-2.1).
 *
 *  - show()   — this brand's target for a month + its pacing. Fresh.
 *  - store()  — set/replace the month's targets (admin/manager; a target drives what
 *               the operator is told about their own performance).
 *  - pacing() — one pacing summary per accessible brand, for the dashboard chip.
 *               A SEPARATE endpoint on purpose: the dashboard runs two engines behind
 *               the `helm:dashboard-parity` gate, and pacing has no business inside
 *               that blast radius. Same pattern as data quality (GO-1.3).
 */
class BrandTargetController extends Controller
{
    private const LIST_CACHE_SECONDS = 600;

    public function show(Request $request, Brand $brand, Pacing $pacing): JsonResponse
    {
        $this->authorize('view', $brand);

        $data  = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? CarbonImmutable::now($brand->timezone ?: 'UTC')->format('Y-m');

        $target = BrandTarget::query()->where('brand_id', $brand->id)->where('month', $month)->first();

        return response()->json([
            'month'  => $month,
            'target' => $target === null ? null : [
                'revenueTarget' => $target->revenue_target,
                'spendCap'      => $target->spend_cap,
                'roasTarget'    => $target->roas_target,
                'merTarget'     => $target->mer_target,
            ],
            // null when no target is set — pacing against an invented goal is worse
            // than showing nothing.
            'pacing' => $pacing->forBrand($brand, $month),
        ]);
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'month'          => ['required', 'date_format:Y-m'],
            // Every target is independently optional; null explicitly CLEARS it.
            'revenue_target' => ['nullable', 'numeric', 'min:0'],
            'spend_cap'      => ['nullable', 'numeric', 'min:0'],
            'roas_target'    => ['nullable', 'numeric', 'min:0'],
            'mer_target'     => ['nullable', 'numeric', 'min:0'],
        ]);

        $target = BrandTarget::updateOrCreate(
            ['brand_id' => $brand->id, 'month' => $data['month']],
            [
                'revenue_target' => $data['revenue_target'] ?? null,
                'spend_cap'      => $data['spend_cap'] ?? null,
                'roas_target'    => $data['roas_target'] ?? null,
                'mer_target'     => $data['mer_target'] ?? null,
                'set_by_user_id' => Auth::id(),
            ],
        );

        return response()->json(['id' => $target->id, 'month' => $target->month], 201);
    }

    public function destroy(Brand $brand, string $month): JsonResponse
    {
        $this->authorize('view', $brand);

        BrandTarget::query()->where('brand_id', $brand->id)->where('month', $month)->delete();

        return response()->json(['ok' => true]);
    }

    /** Pacing for every accessible brand — merged into the dashboard client-side. */
    public function pacing(Pacing $pacing): JsonResponse
    {
        $brands = Brand::query()->where('status', 'active')->orderBy('name')->get();

        $rows = $brands->map(function (Brand $b) use ($pacing): ?array {
            $p = Cache::remember(
                'pacing:brand:' . $b->id . ':' . now()->format('YmdH'),
                self::LIST_CACHE_SECONDS,
                fn (): ?array => $pacing->forBrand($b),
            );

            if ($p === null || $p['revenue'] === null) {
                return null; // no target → no chip. Never a fabricated one.
            }

            return [
                'brandId'     => $b->id,
                'month'       => $p['month'],
                'completeDays' => $p['completeDays'],
                'daysInMonth' => $p['daysInMonth'],
                'pctOfTarget' => $p['revenue']['pctOfTarget'],
                'status'      => $p['revenue']['status'],
                'delta'       => $p['revenue']['delta'],
                'currency'    => $p['currency'],
            ];
        })->filter()->values()->all();

        return response()->json(['rows' => $rows]);
    }
}
