<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Services\Ledger\Ledger;
use App\Services\Ledger\TrackRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Stop/Scale/Fix board (GO-3.2) — the ledger becomes operable.
 *
 * ══ ACCEPT DOES NOT EXECUTE ANYTHING ══ (doctrine §2)
 * There is no code path from this controller to Meta, Google or TikTok. Accepting a
 * recommendation records the operator's INTENT and hands them a checklist of what to do
 * themselves. Helm never touches campaign state.
 *
 * Recording intent is not ceremony: it is the thing the outcome is measured against
 * (GO-3.3). "We advised a pause, you agreed, and 30 days later the waste was gone" is a
 * claim the ledger can defend. Without the accept step there is nothing to score, and
 * the track record — the whole moat — cannot exist.
 *
 * Every transition runs through Ledger, which enforces the state machine (terminal is
 * terminal) and the required dismissal reason.
 */
class RecommendationController extends Controller
{
    public function __construct(private readonly Ledger $ledger) {}

    /** Open recommendations for the board, grouped by kind. */
    public function index(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'status' => ['nullable', 'in:open,accepted,dismissed,expired,all'],
        ]);
        $status = $data['status'] ?? 'open';

        $rows = Recommendation::query()
            ->where('brand_id', $brand->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Recommendation $r): array => $this->payload($r))
            ->all();

        $order = (array) config('ledger.kind_order', []);

        return response()->json([
            'rows'          => $rows,
            'kindLabels'    => (array) config('ledger.kind_labels', []),
            'kindOrder'     => $order,
            'checklists'    => (array) config('ledger.checklists', []),
            // Rendered on every view of the board. Nobody may assume Accept executed.
            'executionNote' => (string) config('ledger.execution_note'),
        ]);
    }

    /**
     * Helm's track record for this brand (GO-3.3) — computed LIVE from ledger rows,
     * never cached. If the engine has a bad month, this endpoint says so.
     */
    public function trackRecord(Brand $brand, TrackRecord $track): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json($track->compute([(int) $brand->id]));
    }

    /**
     * Workspace-wide track record. Scoped to the brands the caller can actually SEE —
     * Brand's global access scope does the filtering, so a team_member's number is
     * computed only over their own brands.
     */
    public function trackRecordAll(TrackRecord $track): JsonResponse
    {
        return response()->json($track->compute(Brand::query()->pluck('id')->all()));
    }

    /**
     * Accept — records INTENT. Executes nothing. Returns the human checklist.
     * Admin/manager only (route-gated): agreeing to advice on a client's account is a
     * decision, not a view.
     */
    public function accept(Brand $brand, Recommendation $recommendation): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $recommendation->brand_id === (int) $brand->id, 404);

        try {
            $this->ledger->transition($recommendation, 'accepted', Auth::user());
        } catch (RuntimeException $e) {
            // e.g. re-accepting something already decided — terminal is terminal.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'    => 'accepted',
            // What YOU now have to do. Helm has done nothing to the ad account.
            'checklist' => (array) config('ledger.checklists.' . $recommendation->kind, []),
            'note'      => (string) config('ledger.execution_note'),
        ]);
    }

    /** Dismiss — REQUIRES a reason. The reason is the honesty record the engine is scored on. */
    public function dismiss(Request $request, Brand $brand, Recommendation $recommendation): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $recommendation->brand_id === (int) $brand->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $this->ledger->transition($recommendation, 'dismissed', Auth::user(), $data['reason']);
        } catch (RuntimeException | InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'dismissed']);
    }

    /** @return array<string, mixed> */
    private function payload(Recommendation $r): array
    {
        return [
            'id'          => $r->id,
            'source'      => $r->source,
            'kind'        => $r->kind,
            'subjectType' => $r->subject_type,
            'subjectId'   => $r->subject_id,
            'title'       => $r->title,
            // The numbers, the rule and the thresholds — expanded on the board, so the
            // operator agrees (or refuses) on the evidence, not on Helm's say-so.
            'evidence'    => $r->evidence,
            'confidence'  => $r->confidence,
            'status'      => $r->status,
            'statusReason' => $r->status_reason,
            // GO-3.3: the outcome is shown in the ledger table — INCLUDING the losses.
            // A track record that only displays its wins is an advertisement.
            'outcome'       => $r->outcome,
            'outcomeMetric' => $r->outcome_metric,
            'measuredAt'    => $r->measured_at?->toIso8601String(),
            'createdAt'   => $r->created_at?->toIso8601String(),
        ];
    }
}
