<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Aggregation\AdsOverviewQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ads hub — per-brand ad-platform Overview (Meta today; platform-agnostic shape
 * for Google/TikTok). Brand-scoped behind the access.brand middleware +
 * BrandPolicy, so a user only sees a brand they're assigned to.
 *
 *   GET /api/brands/{brand}/ads?period=last7|last30|mtd|custom&from=&to=&currency=USD
 */
class AdsController extends Controller
{
    public function __construct(private readonly AdsOverviewQuery $query) {}

    public function show(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'period'   => ['nullable', 'in:last7,last30,mtd,custom'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'in:native,USD,usd'],
        ]);

        return response()->json($this->query->run($brand, $params));
    }
}
