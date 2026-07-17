<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\ReportLayouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * M1 + REV2 R2 (monthly-report-v2-mom.md): the report customizer's CRUD, both
 * layers — same split as CountryTierController.
 *
 *  - show()/store()/destroy()               — this BRAND's own layout override
 *                                              ("Customize report format for this brand").
 *  - showAgencyDefault()/storeAgencyDefault() — Settings -> "Report format" page
 *                                              (master_admin only).
 *
 * A share snapshots the RESOLVED output into the share's own filters json at
 * share-creation time (ReportController::createShare) — nothing here needs to know
 * about shares; resolve() being a pure read of current rows is what makes that
 * snapshot safe.
 */
class ReportLayoutController extends Controller
{
    public function __construct(private readonly ReportLayouts $layouts)
    {
    }

    public function show(Brand $brand, string $reportType): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json([
            'reportType'  => $reportType,
            'sections'    => $this->layouts->resolve($brand, $reportType),
            'hasOverride' => $this->layouts->hasOverride($brand, $reportType),
        ]);
    }

    public function store(Request $request, Brand $brand, string $reportType): JsonResponse
    {
        $this->authorize('update', $brand);

        $sections = $this->validateSections($request);
        $layout   = $this->layouts->save($brand, $reportType, $sections, Auth::id());

        return response()->json(['ok' => true, 'sections' => $layout->sections], 201);
    }

    public function destroy(Brand $brand, string $reportType): JsonResponse
    {
        $this->authorize('update', $brand);

        $this->layouts->clearBrandLayout($brand, $reportType);

        return response()->json(['ok' => true]);
    }

    public function showAgencyDefault(string $reportType): JsonResponse
    {
        return response()->json([
            'reportType' => $reportType,
            'sections'   => $this->layouts->agencyDefaultLayout($reportType),
        ]);
    }

    public function storeAgencyDefault(Request $request, string $reportType): JsonResponse
    {
        $sections = $this->validateSections($request);
        $layout   = $this->layouts->save(null, $reportType, $sections, Auth::id());

        return response()->json(['ok' => true, 'sections' => $layout->sections], 201);
    }

    /**
     * Save the posted layout as the agency default AND apply it to every brand —
     * i.e. drop all per-brand overrides so every brand uses this one format
     * (Kanwar, 2026-07-17 — "a button to apply agency default settings to every
     * brand"). master_admin only (route-gated). Returns how many brand overrides
     * were cleared.
     */
    public function applyToAllBrands(Request $request, string $reportType): JsonResponse
    {
        $sections = $this->validateSections($request);
        $this->layouts->save(null, $reportType, $sections, Auth::id());
        $brandsReset = $this->layouts->clearAllBrandLayouts($reportType);

        return response()->json(['ok' => true, 'brandsReset' => $brandsReset], 200);
    }

    /** @return array<int, array{key: string, enabled: bool, position: int, view: string, settings?: ?array}> */
    private function validateSections(Request $request): array
    {
        $data = $request->validate([
            'sections'            => ['required', 'array', 'max:60'],
            'sections.*.key'      => ['required', 'string', 'max:24'],
            'sections.*.enabled'  => ['required', 'boolean'],
            'sections.*.position' => ['required', 'integer', 'min:0'],
            'sections.*.view'     => ['required', 'string', 'in:chart,table,both'],
            'sections.*.settings' => ['nullable', 'array'],
        ]);

        $keys = array_map(static fn (array $s): string => $s['key'], $data['sections']);
        if (count($keys) !== count(array_unique($keys))) {
            abort(422, 'Duplicate section key in layout.');
        }

        return $data['sections'];
    }
}
