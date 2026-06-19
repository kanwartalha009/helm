<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Aggregation\DashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardQuery $query) {}

    /**
     * GET /api/dashboard
     * Query: date_range, currency, include_returns, group_tag
     */
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'date_range'      => ['nullable', 'string'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'include_returns' => ['nullable', 'boolean'],
            'group_tag'       => ['nullable', 'string', 'max:60'],
            // Brand-manager filter: 'me' (default) | 'all' | a user id.
            'manager'         => ['nullable', 'string', 'max:20'],
            // Year-over-year comparison: comma list of periods + the metric.
            'compare'         => ['nullable', 'string', 'max:60'],
            'metric'          => ['nullable', 'in:net,total'],
        ]);

        return response()->json([
            'rows' => $this->query->run($params),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $params = $request->validate([
            'date_range' => ['nullable', 'string'],
            'currency'   => ['nullable', 'string', 'size:3'],
            'group_tag'  => ['nullable', 'string', 'max:60'],
        ]);

        return response()->json($this->query->summary($params));
    }

    /**
     * GET /api/brands/{brand}/trend
     * Query: from, to, platforms
     */
    public function trend(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $params = $request->validate([
            'from'      => ['required', 'date_format:Y-m-d'],
            'to'        => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'in:shopify,meta,google,tiktok'],
        ]);

        return response()->json([
            'brandId' => $brand->id,
            'series'  => $this->query->trend(
                $brand->id,
                $params['from'],
                $params['to'],
                $params['platforms'] ?? null,
            ),
        ]);
    }
}
