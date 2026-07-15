<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Novedades;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * M4 (monthly-report-v2-mom.md §M4) — S19 "Novedades... written once in
 * Settings, appears in every brand's report that month." This is that
 * write surface: the agency-wide DEFAULT note for a month, master_admin
 * only, same gate as workspace-settings / workspace-country-tiers (see
 * routes/api.php's workspace-settings group — this mirrors that pattern
 * exactly). Per-brand edited copies are written separately via
 * MomSectionController::saveNovedades (admin/manager, brand-scoped).
 */
class WorkspaceNovedadesController extends Controller
{
    public function __construct(private readonly Novedades $novedades)
    {
    }

    public function showAgencyDefault(Request $request): JsonResponse
    {
        $month = $this->resolveMonth($request);
        $row = $this->novedades->agencyDefault($month);

        return response()->json(['month' => $month, 'body' => $row?->body]);
    }

    public function storeAgencyDefault(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'body'  => ['required', 'string', 'max:5000'],
        ]);

        $row = $this->novedades->saveAgencyDefault($data['month'], $data['body'], Auth::id());

        return response()->json(['ok' => true, 'month' => $row->month, 'body' => $row->body]);
    }

    private function resolveMonth(Request $request): string
    {
        $month = $request->query('month');
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $month;
        }

        return CarbonImmutable::now('UTC')->subMonth()->format('Y-m');
    }
}
