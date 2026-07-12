<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Rules\DataQuality;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Data-quality score (GO-1.3). Two surfaces:
 *
 *  - show()  — the full breakdown for ONE brand: every component, its points, the gap
 *              in plain language, and the `fix` dataset that closes it. Computed FRESH
 *              so the score visibly moves the moment a backfill lands.
 *  - index() — one score per accessible brand, for the dashboard chip. Deliberately
 *              served from a short cache: it is ~6 queries per brand and the dashboard
 *              renders ~88 of them. Quality moves on the daily sync, not by the second.
 *
 * NOTE: this is a SEPARATE endpoint on purpose. The dashboard runs two engines behind
 * the `helm:dashboard-parity` gate; merging the chip client-side keeps quality out of
 * that blast radius entirely.
 *
 * Brand visibility rides the Brand model's global access scope — a team member only
 * ever sees (and scores) the brands they are attached to.
 */
class DataQualityController extends Controller
{
    private const LIST_CACHE_SECONDS = 900;

    public function show(Brand $brand, DataQuality $quality): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json($quality->forBrand($brand));
    }

    public function index(DataQuality $quality): JsonResponse
    {
        $brands = Brand::query()->where('status', 'active')->orderBy('name')->get();

        $rows = $brands->map(function (Brand $b) use ($quality): array {
            $q = Cache::remember(
                'quality:brand:' . $b->id . ':' . now()->format('YmdH') . ':' . intdiv((int) now()->format('i'), 15),
                self::LIST_CACHE_SECONDS,
                fn (): array => $quality->forBrand($b),
            );

            return [
                'brandId'   => $b->id,
                'slug'      => $b->slug,
                'score'     => $q['score'],
                'tier'      => $q['tier'],
                'meetsGate' => $q['meetsGate'],
            ];
        })->all();

        return response()->json([
            'threshold' => (int) config('quality.threshold', 70),
            'rows'      => $rows,
        ]);
    }
}
