<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ProductCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Operator-entered product costs (GO-1.2). The override/fallback for Shopify's
 * nullable `InventoryItem.unitCost`. Admin/manager only (route-gated) — a cost drives
 * every margin number a client sees.
 *
 * Costs are EFFECTIVE-DATED: saving a cost records it from `effective_from` (default
 * today) forward, so a price change never silently rewrites last quarter's margin.
 * Re-saving the same (product, date) updates that row rather than stacking duplicates.
 */
class BrandProductCostController extends Controller
{
    /** GET — the cost history for a brand (most recent first), for the UI's cost drawer. */
    public function index(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $rows = ProductCost::query()
            ->where('brand_id', $brand->id)
            ->orderByDesc('effective_from')
            ->orderBy('product_key')
            ->limit(500)
            ->get()
            ->map(static fn (ProductCost $c): array => [
                'id'            => $c->id,
                'productKey'    => (string) $c->product_key,
                'unitCost'      => round((float) $c->unit_cost, 2),
                'currency'      => (string) $c->currency,
                'effectiveFrom' => $c->effective_from->toDateString(),
            ])
            ->all();

        return response()->json(['rows' => $rows]);
    }

    /** PUT — set (or correct) one product's unit cost. */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'product_key'    => ['required', 'string', 'max:191'],
            // A cost of 0 is a real, deliberate statement ("this costs us nothing");
            // it is only ever set by hand, never inferred. Negative is nonsense.
            'unit_cost'      => ['required', 'numeric', 'min:0'],
            'currency'       => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $cost = ProductCost::updateOrCreate(
            [
                'brand_id'       => $brand->id,
                'product_key'    => mb_strtolower(trim($data['product_key'])),
                'effective_from' => $data['effective_from'] ?? now($brand->timezone ?: 'UTC')->toDateString(),
            ],
            [
                'unit_cost'      => round((float) $data['unit_cost'], 2),
                'currency'       => strtoupper($data['currency'] ?? ($brand->base_currency ?: 'USD')),
                'set_by_user_id' => Auth::id(),
            ],
        );

        return response()->json([
            'id'            => $cost->id,
            'productKey'    => (string) $cost->product_key,
            'unitCost'      => round((float) $cost->unit_cost, 2),
            'currency'      => (string) $cost->currency,
            'effectiveFrom' => $cost->effective_from->toDateString(),
        ], 201);
    }

    /** DELETE — drop a manual cost row (falls back to Shopify's cost, then brand margin). */
    public function destroy(Brand $brand, ProductCost $cost): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $cost->brand_id === (int) $brand->id, 404);

        $cost->delete();

        return response()->json(['ok' => true]);
    }
}
