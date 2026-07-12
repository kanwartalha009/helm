<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Rules\Forecast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Revenue forecast baseline (GO-2.3). Seasonal-naive + drift (fpp3 §5.2).
 *
 * Returns status='insufficient_history' — and no numbers — when the brand is too new
 * or last year doesn't cover the window. That refusal is deliberate: an invented
 * forecast is worse than none.
 */
class BrandForecastController extends Controller
{
    public function show(Request $request, Brand $brand, Forecast $forecast): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'horizon' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);

        return response()->json(
            $forecast->forBrand($brand, $data['horizon'] ?? null)
                + ['monthEnd' => $forecast->monthEndProjection($brand)],
        );
    }
}
