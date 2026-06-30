<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Aggregation\AudienceQuery;
use App\Services\Aggregation\DashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardQuery $query,
        private readonly AudienceQuery $audience,
    ) {}

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

    /**
     * GET /api/dashboard/audience
     *
     * Meta spend split by a breakdown axis (audience segments / placement /
     * age+gender / country / device) over a period, per brand. The manager +
     * currency filters mirror /dashboard so switching views keeps the filter bar
     * meaningful. Returns the column set (shared across rows) and one row per
     * Meta brand. See AudienceQuery for the no-ASC remainder logic.
     */
    public function audience(Request $request): JsonResponse
    {
        $params = $request->validate([
            'currency'  => ['nullable', 'string', 'size:3'],
            'manager'   => ['nullable', 'string', 'max:20'],
            'breakdown' => ['nullable', 'in:audience,age_gender,placement,country,device'],
            'period'    => ['nullable', 'in:last7,last30,mtd'],
        ]);

        return response()->json($this->audience->run($params));
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
