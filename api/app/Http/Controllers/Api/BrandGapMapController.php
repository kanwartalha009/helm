<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\AdsLibrary\GapMap;
use Illuminate\Http\JsonResponse;

/**
 * Competitor gap map for one brand (GO-3.4).
 *
 * Competitor activity is PROXY (public Ad Library presence — no spend exists for
 * commercial EU ads). Our own side is VERIFIED (real money). The payload keeps the two
 * labelled separately and never mixes them, and it states that a gap is a question worth
 * asking, not proof that a market pays off.
 */
class BrandGapMapController extends Controller
{
    public function show(Brand $brand, GapMap $gapMap): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json($gapMap->forBrand($brand));
    }
}
