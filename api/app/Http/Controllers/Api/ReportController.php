<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ReportShare;
use App\Models\WorkspaceSetting;
use App\Reports\Contracts\ReportFilters;
use App\Reports\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Reporting engine API (feature spec — Reporting & Creative Intelligence, slice 2.0).
 *
 *   GET  /api/reports                                  list available report types
 *   GET  /api/brands/{brand}/reports/{type}            build a report (period + compare params)
 *   POST /api/brands/{brand}/reports/{type}/shares     snapshot → public token (edited narrative saved)
 *   GET  /api/r/{token}                                public, read-only view of a snapshot
 *
 * Brand routes sit behind the access.brand middleware + the BrandPolicy, so a
 * user only reports on brands they can see. The public token route is
 * deliberately unauthenticated but read-only and unguessable.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public function index(): JsonResponse
    {
        return response()->json(['reports' => $this->registry->list()]);
    }

    public function show(Request $request, Brand $brand, string $type): JsonResponse
    {
        $this->authorize('view', $brand);

        if (! $this->registry->has($type)) {
            abort(404, 'Unknown report type.');
        }

        $filters = ReportFilters::fromArray($request->query());
        $payload = $this->registry->for($type)->build($brand, $filters);
        $payload['branding'] = $this->branding();

        return response()->json($payload);
    }

    public function createShare(Request $request, Brand $brand, string $type): JsonResponse
    {
        $this->authorize('view', $brand);

        if (! $this->registry->has($type)) {
            abort(404, 'Unknown report type.');
        }

        $data = $request->validate([
            'filters'       => ['nullable', 'array'],
            'content'       => ['nullable', 'array'],   // edited narrative + comments
            'expiresInDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $share = ReportShare::create([
            'brand_id'           => $brand->id,
            'report_type'        => $type,
            'filters'            => $data['filters'] ?? null,
            'content'            => $data['content'] ?? null,
            'created_by_user_id' => Auth::id(),
            'expires_at'         => isset($data['expiresInDays']) ? now()->addDays($data['expiresInDays']) : null,
        ]);

        return response()->json(['token' => $share->token, 'url' => "/r/{$share->token}"], 201);
    }

    /**
     * Public read-only render of a snapshot. Rebuilds the report data live from
     * the saved filters (data is recent and small), and returns the operator's
     * saved narrative/comments verbatim. The brand is loaded without the RBAC
     * global scope — there is no authenticated user on this route, and access is
     * gated by the unguessable token instead.
     */
    public function publicShow(string $token): JsonResponse
    {
        $share = ReportShare::query()->where('token', $token)->first();
        if (! $share || $share->isExpired() || ! $this->registry->has($share->report_type)) {
            abort(404);
        }

        $brand = Brand::withoutGlobalScope('access')->find($share->brand_id);
        if (! $brand) {
            abort(404);
        }

        $filters = ReportFilters::fromArray((array) ($share->filters ?? []));
        $payload = $this->registry->for($share->report_type)->build($brand, $filters);

        $payload['content']  = $share->content;
        $payload['branding'] = $this->branding();
        $payload['shared']   = true;

        return response()->json($payload);
    }

    /** Per-agency white-label theme (workspace-level). Sent with every report. */
    private function branding(): array
    {
        return (array) WorkspaceSetting::getValue('report_branding', [
            'agency_name' => 'Roasdriven',
            'accent'      => '#1f6f5c',
            'footer_text' => 'Powered by novasolution.ae',
        ]);
    }
}
