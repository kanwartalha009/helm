<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ReportCommentary;
use App\Models\ReportShare;
use App\Models\WorkspaceSetting;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\MomSectionRegistry;
use App\Services\ReportLayouts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * REV2 R6 addendum / M5 (Kanwar, 2026-07-15 — "complete the full mom report")
 * — public share links for mom, mirroring v1's ReportController flow (POST
 * .../shares -> token, GET /r/{token} public read-only) but adapted for
 * mom's section-streamed shape (M0's own architecture mandate): v1's
 * publicShow() rebuilds ONE full report payload from a single
 * ReportRegistry->for($type)->build() call, which mom deliberately has no
 * equivalent of — 'mom' isn't even registered in ReportRegistry (verified;
 * it's a separate MomSectionRegistry, per-section, not per-report-type). This
 * controller instead exposes a public SHELL endpoint (returns the
 * SNAPSHOTTED section manifest, never live-re-resolved — "a client link
 * never reshuffles after the agency re-customizes", M1) and a public
 * per-SECTION endpoint (rebuilds one section live, same "data is recent and
 * small" reasoning as v1's own publicShow docblock), both gated only by the
 * unguessable token — no Sanctum auth, same contract as v1's `r/{token}`.
 *
 * Reuses the SAME `report_shares` table as v1 (report_type='mom', already a
 * free string column) rather than a new table — `content.sections` holds the
 * resolved-layout SNAPSHOT (enabled + ready sections only, at share-creation
 * time); `filters` holds the month/compare selection, replayed into
 * ReportFilters::fromArray() on every public section fetch so the numbers
 * stay pinned to what the agency was looking at when they hit Share.
 *
 * "Shared view AUTO-HIDES incomplete sections" (M2/M5's no-empty-fields law):
 * publicSection() never attaches a `backfillDataset` CTA hint (an
 * internal-only affordance) — a section whose live rebuild isn't 'ok' is
 * still returned honestly so the public frontend can choose to hide it,
 * just without any internal "fix this" chrome a client has no use for.
 */
class MomShareController extends Controller
{
    public function __construct(
        private readonly ReportLayouts $layouts,
        private readonly MomSectionRegistry $registry,
    ) {
    }

    public function create(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'filters'       => ['nullable', 'array'],
            'expiresInDays' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $layout = $this->layouts->resolve($brand, 'mom');
        // Snapshot only ENABLED + actually-BUILT sections — a section the
        // customizer has toggled off, or one that isn't built yet, was never
        // going to render for this brand anyway; freezing it into the share
        // wouldn't change that, it would just carry dead weight a public
        // viewer can't do anything with.
        $sections = array_values(array_filter(
            $layout,
            fn (array $s): bool => ($s['enabled'] ?? false) && $this->registry->has((string) $s['key']),
        ));

        $share = ReportShare::create([
            'brand_id'           => $brand->id,
            'report_type'        => 'mom',
            'filters'            => $data['filters'] ?? [],
            'content'            => ['sections' => $sections],
            'created_by_user_id' => Auth::id(),
            'expires_at'         => isset($data['expiresInDays']) ? now()->addDays($data['expiresInDays']) : null,
        ]);

        return response()->json(['token' => $share->token, 'url' => "/mom/r/{$share->token}"], 201);
    }

    /** Public, read-only shell — the section manifest as SNAPSHOTTED at share time, never live-re-resolved. */
    public function publicShell(string $token): JsonResponse
    {
        $share = $this->resolveShare($token);
        $brand = Brand::withoutGlobalScope('access')->find($share->brand_id);
        if ($brand === null) {
            abort(404);
        }

        $tz      = $brand->timezone ?: 'UTC';
        $filters = ReportFilters::fromArray((array) ($share->filters ?? []));
        $window  = $filters->monthWindow($tz);

        $monthStart = $window !== null
            ? CarbonImmutable::parse($window[0], $tz)
            : CarbonImmutable::now($tz)->startOfMonth()->subMonth();
        $monthEnd = $window !== null ? CarbonImmutable::parse($window[1], $tz) : $monthStart->endOfMonth();

        return response()->json([
            'reportType' => 'mom',
            'brand'      => ['name' => $brand->name, 'slug' => $brand->slug, 'baseCurrency' => $brand->base_currency],
            'currency'   => $brand->base_currency ?: 'USD',
            'month'      => [
                'label' => $monthStart->isoFormat('MMMM YYYY'),
                'start' => $monthStart->toDateString(),
                'end'   => $monthEnd->toDateString(),
            ],
            // Snapshotted at share-creation time, per M1's own share-immunity
            // rule — deliberately NOT ReportLayouts::resolve() called fresh
            // here, or a later agency re-customization would reshuffle a link
            // a client already has open.
            'sections' => $share->content['sections'] ?? [],
            'branding' => $this->branding(),
            'shared'   => true,
        ]);
    }

    /** Per-agency white-label theme, same source + default v1's ReportController::branding() reads. */
    private function branding(): array
    {
        return (array) WorkspaceSetting::getValue('report_branding', [
            'agency_name' => 'Roasdriven',
            'accent'      => '#1f6f5c',
            'footer_text' => 'Powered by novasolution.ae',
        ]);
    }

    /** Public, read-only rebuild of ONE section, pinned to the share's snapshotted filters. */
    public function publicSection(string $token, string $key): JsonResponse
    {
        $share    = $this->resolveShare($token);
        $snapshot = collect($share->content['sections'] ?? []);
        if (! $snapshot->contains(fn (array $s): bool => ($s['key'] ?? null) === $key)) {
            // Not in the snapshot — disabled, never built, or a stale/tampered
            // key. The same honest 404 a share should give for anything it
            // never promised to show, never a 500.
            abort(404);
        }

        $brand = Brand::withoutGlobalScope('access')->find($share->brand_id);
        if ($brand === null || ! $this->registry->has($key)) {
            abort(404);
        }

        $filters = ReportFilters::fromArray((array) ($share->filters ?? []));

        try {
            $payload = $this->registry->for($key)->build($brand, $filters);
        } catch (Throwable $e) {
            Log::warning('mom_share_section.failed', ['key' => $key, 'brand_id' => $brand->id, 'error' => $e->getMessage()]);
            $payload = ['key' => $key, 'status' => 'no_data'];
        }

        // Read-only commentary, if any — "carried into shares" per M2's own
        // intent. No write path exists on this controller; a public viewer
        // can never edit it, only see what the agency already wrote.
        $window = $filters->monthWindow($brand->timezone ?: 'UTC');
        if ($window !== null) {
            $commentary = ReportCommentary::query()
                ->where('brand_id', $brand->id)
                ->where('report_type', 'mom')
                ->where('month', CarbonImmutable::parse($window[0])->format('Y-m'))
                ->where('section_key', $key)
                ->first();
            if ($commentary !== null) {
                $payload['commentary'] = $commentary->commentary;
                $payload['todo']       = $commentary->todo;
            }
        }

        return response()->json($payload);
    }

    private function resolveShare(string $token): ReportShare
    {
        $share = ReportShare::query()->where('token', $token)->where('report_type', 'mom')->first();
        if ($share === null || $share->isExpired()) {
            abort(404);
        }

        return $share;
    }
}
