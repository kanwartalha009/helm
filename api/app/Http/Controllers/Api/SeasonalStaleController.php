<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Rules\SeasonalStale;
use Illuminate\Http\JsonResponse;

/**
 * Seasonal-stale creatives for the ads hub (GO-3.1).
 *
 * The trigger is a keyword+date RULE — no model is consulted anywhere in this path, and
 * the payload says so, so nobody reading the badge has to wonder whether an AI guessed.
 */
class SeasonalStaleController extends Controller
{
    public function index(Brand $brand, SeasonalStale $detector): JsonResponse
    {
        $this->authorize('view', $brand);

        $rows = $detector->forBrand($brand);

        return response()->json([
            'currency' => $brand->base_currency ?: 'USD',
            'count'    => count($rows),
            // Total money burning on dead hooks in the live window — the reason to care.
            'spendAtRisk' => round(array_sum(array_column($rows, 'spend')), 2),
            'rows'     => $rows,
            'trigger'  => 'Matched a season keyword AND the season is over (plus a grace period). '
                . 'A rule, not a model — the matched words are listed on every row.',
        ]);
    }
}
