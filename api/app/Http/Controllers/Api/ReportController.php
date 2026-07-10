<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ReportShare;
use App\Models\WorkspaceSetting;
use App\Reports\Contracts\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Llm\LlmException;
use App\Services\Llm\LlmManager;
use App\Services\Llm\NarrativeGenerator;
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
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly NarrativeGenerator $narrative,
        private readonly LlmManager $llm,
    ) {}

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

        // LLM layer (D-016): the stored draft for THIS filter selection (if
        // any) + whether generation is available, so the SPA renders either
        // the blocks, a "Generate with AI" button, or a "add your key" hint.
        $payload['narrative'] = $this->narrative->find($brand, $type, $filters)?->toPayload();
        $payload['llm']       = [
            'enabled'  => $this->llm->enabled(),
            'provider' => config('llm.provider'),
        ];

        return response()->json($payload);
    }

    /**
     * POST /api/brands/{brand}/reports/{type}/narrative
     * Generate (or regenerate) the four narrative blocks for the current
     * filter selection. Explicitly operator-triggered — never automatic —
     * so LLM spend is a deliberate act (D-016 cost stance).
     */
    public function generateNarrative(Request $request, Brand $brand, string $type): JsonResponse
    {
        $this->authorize('view', $brand);

        if (! $this->registry->has($type)) {
            abort(404, 'Unknown report type.');
        }

        $data = $request->validate([
            'period'   => ['nullable', 'in:last7,last30,mtd,custom'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d'],
            'compare'  => ['nullable', 'in:previous,last_year,none'],
            'language' => ['nullable', 'in:en,es'],
        ]);

        if (! $this->llm->enabled()) {
            return response()->json([
                'message' => 'No LLM key on file. Add one at Settings → Platform keys → AI / LLM, then run php artisan llm:diagnose.',
            ], 422);
        }

        $filters = ReportFilters::fromArray($data);

        try {
            $row = $this->narrative->generate($brand, $type, $filters, $data['language'] ?? null);
        } catch (LlmException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['narrative' => $row->toPayload()], 201);
    }

    /**
     * PATCH /api/brands/{brand}/reports/{type}/narrative
     * Save the operator's edited copy of the blocks (D-016: always edited
     * before send). Editing never touches the model's draft.
     */
    public function saveNarrative(Request $request, Brand $brand, string $type): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'period'              => ['nullable', 'in:last7,last30,mtd,custom'],
            'from'                => ['nullable', 'date_format:Y-m-d'],
            'to'                  => ['nullable', 'date_format:Y-m-d'],
            'compare'             => ['nullable', 'in:previous,last_year,none'],
            'blocks'              => ['required', 'array'],
            'blocks.observations' => ['required', 'string', 'max:8000'],
            'blocks.actions'      => ['required', 'string', 'max:8000'],
            'blocks.plan'         => ['required', 'string', 'max:8000'],
            'blocks.ideas'        => ['required', 'string', 'max:8000'],
        ]);

        $filters = ReportFilters::fromArray($data);
        $row     = $this->narrative->find($brand, $type, $filters);

        if ($row === null) {
            abort(404, 'No narrative draft exists for this selection — generate one first.');
        }

        $row->update([
            'edited_blocks' => [
                'observations' => $data['blocks']['observations'],
                'actions'      => $data['blocks']['actions'],
                'plan'         => $data['blocks']['plan'],
                'ideas'        => $data['blocks']['ideas'],
            ],
            'edited_at' => now(),
        ]);

        return response()->json(['narrative' => $row->refresh()->toPayload()]);
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
