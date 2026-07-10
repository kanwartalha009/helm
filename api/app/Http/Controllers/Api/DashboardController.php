<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Aggregation\AudienceQuery;
use App\Services\Aggregation\DashboardQuery;
use App\Services\Aggregation\DashboardQuerySetBased;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardQuery $query,
        private readonly DashboardQuerySetBased $setBased,
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
            // Rolling comparison window (days) for the far-right block: 7 / 30 / 90.
            'window'          => ['nullable', 'in:7,30,90'],
        ]);

        // Engine flag (config/helm.php): legacy per-brand queries vs the
        // set-based engine. Same output contract — verified by
        // `php artisan helm:dashboard-parity` before any flip.
        $engine = config('helm.dashboard_engine') === 'set' ? $this->setBased : $this->query;

        return response()->json([
            'rows' => $engine->run($params),
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
            'breakdown' => ['nullable', 'in:audience,age_gender,placement_platform,placement,region,country,device'],
            'period'    => ['nullable', 'in:last7,last30,mtd'],
        ]);

        return response()->json($this->audience->run($params));
    }

    // summary() and trend() were deleted 2026-07-10 (D-020): both were stubs
    // (hardcoded zeros / empty series) on live routes with no SPA consumer.
    // Re-add with real daily_metrics implementations when a feature needs them.
}
