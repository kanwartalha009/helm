<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Digest\WeeklyDigest;
use Illuminate\Http\JsonResponse;

/**
 * The in-app weekly digest (GO-3.5).
 *
 * Works with or without Slack — Slack is optional DELIVERY, not the feature. Brand
 * visibility rides the Brand model's global access scope, so a team_member's digest
 * covers only their own brands.
 */
class DigestController extends Controller
{
    public function show(WeeklyDigest $digest): JsonResponse
    {
        return response()->json($digest->compose());
    }
}
