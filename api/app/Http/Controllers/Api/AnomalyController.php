<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The anomaly feed (GO-2.4).
 *
 *  - index()   — open anomalies for one brand (the brand-detail strip).
 *  - feed()    — open anomalies across every accessible brand (the dashboard bell).
 *                A side endpoint, so the dashboard's two parity-gated engines stay
 *                untouched (same pattern as quality + pacing).
 *  - dismiss() — resolve one, WITH A REQUIRED REASON.
 *
 * The required reason is not UI politeness. When GO-3's ledger begins scoring Helm's
 * own suggestions, a dismissal with no stated reason would let the engine quietly bury
 * its misses — "dismissed" would become an unfalsifiable excuse. The reason is the
 * honesty record, so it is enforced here in validation, not merely in the form.
 */
class AnomalyController extends Controller
{
    public function index(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate(['include_resolved' => ['nullable', 'boolean']]);

        $rows = Anomaly::query()
            ->where('brand_id', $brand->id)
            ->when(! ($data['include_resolved'] ?? false), fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('date')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->limit(100)
            ->get()
            ->map(fn (Anomaly $a): array => $this->payload($a))
            ->all();

        return response()->json(['rows' => $rows]);
    }

    /** Open anomalies across accessible brands — the dashboard bell. */
    public function feed(): JsonResponse
    {
        $brandIds = Brand::query()->where('status', 'active')->pluck('id'); // global access scope applies

        $rows = Anomaly::query()
            ->whereIn('brand_id', $brandIds)
            ->whereNull('resolved_at')
            ->with('brand:id,name,slug')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->orderByDesc('date')
            ->limit(50)
            ->get()
            ->map(fn (Anomaly $a): array => $this->payload($a) + [
                'brand' => ['name' => $a->brand?->name, 'slug' => $a->brand?->slug],
            ])
            ->all();

        return response()->json([
            'open' => Anomaly::query()->whereIn('brand_id', $brandIds)->whereNull('resolved_at')->count(),
            'rows' => $rows,
        ]);
    }

    /** Dismiss an anomaly. The reason is REQUIRED — see the class docblock. */
    public function dismiss(Request $request, Brand $brand, Anomaly $anomaly): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $anomaly->brand_id === (int) $brand->id, 404);

        $data = $request->validate([
            // Enforced server-side, not just in the form.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $anomaly->update([
            'resolved_at'         => now(),
            'resolved_by_user_id' => Auth::id(),
            'resolution_reason'   => $data['reason'],
        ]);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(Anomaly $a): array
    {
        return [
            'id'       => $a->id,
            'date'     => $a->date->toDateString(),
            'kind'     => $a->kind,
            'subject'  => $a->subject,
            'severity' => $a->severity,
            // Always shipped: the numbers, the rule and the threshold, so the operator
            // can re-derive the alert by hand instead of taking it on faith.
            'evidence' => $a->evidence,
            'resolvedAt' => $a->resolved_at?->toIso8601String(),
            'resolutionReason' => $a->resolution_reason,
        ];
    }
}
