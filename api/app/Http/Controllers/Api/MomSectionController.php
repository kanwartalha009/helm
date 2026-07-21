<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ReportCommentary;
use App\Models\ReportNextStep;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\MomSectionRegistry;
use App\Services\Novedades;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * M2 (monthly-report-v2-mom.md §M2): one section per request — the
 * section-streamed architecture M0 exists to teach. show() is fault-isolated:
 * a section builder that throws degrades to 'no_data', it never 500s and never
 * takes any other section (or the report shell) down with it.
 *
 *   GET  brands/{brand}/reports/mom/sections/{key}             this section's data
 *   GET  brands/{brand}/reports/mom/sections/{key}/commentary  its commentary + To-Do
 *   PUT  brands/{brand}/reports/mom/sections/{key}/commentary  save them (admin/manager)
 *
 * M4 adds two more editorial write paths, kept on this controller rather than
 * new dedicated ones since they're small, brand-scoped, and follow the exact
 * same authorize/validate/updateOrCreate shape as saveCommentary():
 *   PUT  brands/{brand}/reports/mom/next-steps  save S0's checklist for a month
 *   PUT  brands/{brand}/reports/mom/novedades   save S19's per-brand copy for a month
 */
class MomSectionController extends Controller
{
    public function __construct(
        private readonly MomSectionRegistry $registry,
        private readonly Novedades $novedades,
    ) {
    }

    public function show(Request $request, Brand $brand, string $key): JsonResponse
    {
        $this->authorize('view', $brand);

        if (! $this->registry->has($key)) {
            // Real key, not built yet — an honest "coming soon", not a 404 or
            // a fabricated payload. The customizer/manifest already know this
            // (MomReport::build()'s sections[].ready), this is the same signal
            // at the section endpoint itself for a client hitting it directly.
            return response()->json(['key' => $key, 'status' => 'not_built_yet']);
        }

        $filters = ReportFilters::fromArray($request->query());

        try {
            $payload = $this->registry->for($key)->build($brand, $filters);
        } catch (Throwable $e) {
            Log::warning('mom_section.failed', ['key' => $key, 'brand_id' => $brand->id, 'error' => $e->getMessage()]);

            return response()->json(['key' => $key, 'status' => 'no_data']);
        }

        // M5 — attach the backfill dataset a 'needs_source' section's own gap
        // maps to, so the frontend's "Backfill this data" chip never needs its
        // own copy of MomSectionRegistry::DATASET_MAP.
        if (($payload['status'] ?? null) === 'needs_source' && ! isset($payload['backfillDataset'])) {
            $payload['backfillDataset'] = $this->registry->datasetFor($key);
        }

        return response()->json($payload);
    }

    public function showCommentary(Request $request, Brand $brand, string $key): JsonResponse
    {
        $this->authorize('view', $brand);

        $month = $this->resolveMonth($request, $brand);
        $row = ReportCommentary::query()
            ->where('brand_id', $brand->id)
            ->where('report_type', 'mom')
            ->where('month', $month)
            ->where('section_key', $key)
            ->first();

        return response()->json([
            'month'      => $month,
            'sectionKey' => $key,
            'commentary' => $row?->commentary,
            'todo'       => $row?->todo ?? [],
        ]);
    }

    public function saveCommentary(Request $request, Brand $brand, string $key): JsonResponse
    {
        // Collaborative notes: any user with access to the brand can add/edit the
        // shared, DB-backed commentary + To-Do (Kanwar, 2026-07-20). Broader than
        // the admin/manager `update` gate that guards brand settings — the point
        // is that team members A, B and C can all contribute to the same monthly
        // note. Persistence is unchanged (brand+month+section, never per-user).
        $this->authorize('comment', $brand);

        $data = $request->validate([
            'month'         => ['required', 'date_format:Y-m'],
            'commentary'    => ['nullable', 'string', 'max:5000'],
            'todo'          => ['nullable', 'array', 'max:50'],
            'todo.*.text'   => ['required_with:todo', 'string', 'max:300'],
            'todo.*.done'   => ['sometimes', 'boolean'],
        ]);

        ReportCommentary::query()->updateOrCreate(
            ['brand_id' => $brand->id, 'report_type' => 'mom', 'month' => $data['month'], 'section_key' => $key],
            [
                'commentary' => $data['commentary'] ?? null,
                'todo'       => $data['todo'] ?? null,
                'updated_by' => Auth::id(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * M4 — S0 "Next Steps": full-replace save of one month's checklist. The
     * SPA always sends the complete desired items array (same full-replace
     * contract as ReportLayouts::save()) — carried-forward items computed by
     * SNextStepsSection::build() are only persisted here, the moment the
     * agency actually edits/confirms them.
     */
    public function saveNextSteps(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $data = $request->validate([
            'month'                => ['required', 'date_format:Y-m'],
            'items'                => ['present', 'array', 'max:100'],
            'items.*.text'         => ['required', 'string', 'max:300'],
            'items.*.group'        => ['required', Rule::in(['mes', 'ads', 'countries', 'email', 'cro'])],
            'items.*.status'       => ['required', Rule::in(['open', 'done', 'dropped'])],
            'items.*.carriedFrom'  => ['nullable', 'string', 'max:7'],
        ]);

        $items = array_map(static fn (array $i): array => [
            'text'        => $i['text'],
            'group'       => $i['group'],
            'status'      => $i['status'],
            'carried_from' => $i['carriedFrom'] ?? null,
        ], $data['items']);

        ReportNextStep::query()->updateOrCreate(
            ['brand_id' => $brand->id, 'month' => $data['month']],
            ['items' => $items, 'updated_by' => Auth::id()],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * M4 — S19 "Novedades": save THIS brand's edited copy for a month (the
     * agency-wide default is written separately, Settings-only, via
     * WorkspaceNovedadesController — master_admin gated there).
     */
    public function saveNovedades(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'body'  => ['required', 'string', 'max:5000'],
        ]);

        $this->novedades->saveBrandCopy($brand, $data['month'], $data['body'], Auth::id());

        return response()->json(['ok' => true]);
    }

    private function resolveMonth(Request $request, Brand $brand): string
    {
        $month = $request->query('month');
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $month;
        }

        return CarbonImmutable::now($brand->timezone ?: 'UTC')->subMonth()->format('Y-m');
    }
}
