<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Reports\Support\TruthSpine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Triangulated truth for one brand (GO-1.4): MER (store truth) as the spine, each
 * platform's OWN reported ROAS beside it carrying its documented bias direction.
 *
 * Served as its own endpoint rather than being threaded into the dashboard row payload:
 * the dashboard runs two engines behind the `helm:dashboard-parity` gate, and adding a
 * field there means changing both identically and re-proving parity. Same reasoning as
 * the data-quality chip (GO-1.3).
 */
class BrandTruthController extends Controller
{
    public function show(Request $request, Brand $brand, TruthSpine $truth): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'period'   => ['nullable', 'in:last7,last30,last90,mtd'],
            'currency' => ['nullable', 'in:USD'],
        ]);

        $tz        = $brand->timezone ?: 'UTC';
        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();
        [$start, $end] = match ($data['period'] ?? 'last30') {
            'last7'  => [$yesterday->subDays(6), $yesterday],
            'last90' => [$yesterday->subDays(89), $yesterday],
            'mtd'    => [CarbonImmutable::now($tz)->startOfMonth(), $yesterday],
            default  => [$yesterday->subDays(29), $yesterday],
        };

        $connected = $brand->connections()->where('status', 'active')->pluck('platform')->unique()->values()->all();
        $usd       = ($data['currency'] ?? null) === 'USD';

        return response()->json([
            'periodStart' => $start->toDateString(),
            'periodEnd'   => $end->toDateString(),
            'currency'    => $usd ? 'USD' : ($brand->base_currency ?: 'USD'),
        ] + $truth->forBrand((int) $brand->id, $connected, $start->toDateString(), $end->toDateString(), $usd));
    }
}
