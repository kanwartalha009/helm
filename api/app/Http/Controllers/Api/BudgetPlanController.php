<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BudgetPlan;
use App\Services\Rules\BudgetPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Budget planner (GO-2.2). Reads trailing-90d actuals + the operator's plan for a
 * month; writes ONLY to budget_plans.
 *
 * It cannot and does not touch an ad platform. There is no code path from this
 * controller to Meta/Google/TikTok — by design, per doctrine. Helm plans; humans execute.
 */
class BudgetPlanController extends Controller
{
    public function show(Request $request, Brand $brand, BudgetPlanner $planner): JsonResponse
    {
        $this->authorize('view', $brand);

        $data  = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        // Default to NEXT month — this is a forward-planning surface.
        $month = $data['month'] ?? CarbonImmutable::now($brand->timezone ?: 'UTC')->addMonth()->format('Y-m');

        return response()->json($planner->forBrand($brand, $month));
    }

    /** Set one planned cell. Admin/manager only (route-gated). */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'month'         => ['required', 'date_format:Y-m'],
            'platform'      => ['required', 'in:meta,google,tiktok'],
            'country'       => ['nullable', 'string', 'max:8'],
            'planned_spend' => ['required', 'numeric', 'min:0'],
            'note'          => ['nullable', 'string', 'max:500'],
        ]);

        $plan = BudgetPlan::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'month'    => $data['month'],
                'platform' => $data['platform'],
                'country'  => $data['country'] ?? '',   // '' = all countries
            ],
            [
                'planned_spend'  => round((float) $data['planned_spend'], 2),
                'note'           => $data['note'] ?? null,
                'set_by_user_id' => Auth::id(),
            ],
        );

        return response()->json(['id' => $plan->id], 201);
    }

    public function destroy(Brand $brand, BudgetPlan $plan): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $plan->brand_id === (int) $brand->id, 404);

        $plan->delete();

        return response()->json(['ok' => true]);
    }
}
