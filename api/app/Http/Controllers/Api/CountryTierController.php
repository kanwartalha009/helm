<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\CountryTiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * M1 (monthly-report-v2-mom.md §M1): country tiers CRUD, both layers.
 *
 *  - show()/store()/destroy()               — this BRAND's own tier set (brand Settings,
 *                                              the PRIMARY UI per Kanwar 2026-07-12).
 *  - showAgencyDefault()/storeAgencyDefault() — the agency-wide DEFAULT set every brand
 *                                              without its own override reads from
 *                                              (Settings -> General, master_admin only,
 *                                              same gate as workspace-settings).
 *
 * Reading is brand-visible (any accessible-brand viewer sees which tier a country is
 * in); writing shapes what the mom report groups countries into for a client meeting,
 * so it's admin/manager only — mirrors BrandTargetController's split exactly.
 */
class CountryTierController extends Controller
{
    public function __construct(private readonly CountryTiers $tiers)
    {
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $rows = $this->tiers->tiersFor($brand);

        return response()->json([
            'tiers'       => $rows->map(fn ($t) => $this->present($t))->values(),
            'resolved'    => $this->tiers->resolve($brand),
            'hasOverride' => $this->tiers->hasOverride($brand),
        ]);
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $rows = $this->validateRows($request);
        $this->tiers->replaceBrandTiers($brand, $rows, Auth::id());

        return response()->json(['ok' => true, 'tiers' => $this->tiers->tiersFor($brand)->map(fn ($t) => $this->present($t))->values()], 201);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $this->tiers->clearBrandTiers($brand);

        return response()->json(['ok' => true]);
    }

    public function showAgencyDefault(): JsonResponse
    {
        $rows = $this->tiers->agencyDefaultTiers();

        return response()->json(['tiers' => $rows->map(fn ($t) => $this->present($t))->values()]);
    }

    public function storeAgencyDefault(Request $request): JsonResponse
    {
        $rows = $this->validateRows($request);
        $this->tiers->replaceAgencyDefaultTiers($rows, Auth::id());

        return response()->json(['ok' => true, 'tiers' => $this->tiers->agencyDefaultTiers()->map(fn ($t) => $this->present($t))->values()], 201);
    }

    /** @return array<int, array{tier_key: string, label: string, color: string, countries: array<int, string>}> */
    private function validateRows(Request $request): array
    {
        $data = $request->validate([
            'tiers'                    => ['required', 'array', 'max:30'],
            'tiers.*.tier_key'         => ['required', 'string', 'max:24'],
            'tiers.*.label'            => ['required', 'string', 'max:48'],
            'tiers.*.color'            => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'tiers.*.countries'        => ['nullable', 'array'],
            'tiers.*.countries.*'      => ['string', 'size:2'],
        ]);

        $keys = array_map(static fn (array $t): string => strtoupper($t['tier_key']), $data['tiers']);
        if (count($keys) !== count(array_unique($keys))) {
            abort(422, 'Duplicate tier_key — every tier needs a unique key.');
        }

        return $data['tiers'];
    }

    /** @return array<string, mixed> */
    private function present(\App\Models\CountryTier $t): array
    {
        return [
            'id'        => $t->id,
            'tierKey'   => $t->tier_key,
            'label'     => $t->label,
            'color'     => $t->color,
            'countries' => $t->countries ?? [],
            'position'  => $t->position,
        ];
    }
}
