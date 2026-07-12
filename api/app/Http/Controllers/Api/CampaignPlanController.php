<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\CampaignPlan;
use App\Models\MarketMoment;
use App\Services\Ledger\Ledger;
use App\Services\Playbook\PlanGenerator;
use App\Services\Playbook\PlanNarrator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Seasonal campaign plans (GO-4.3).
 *
 * Generating a plan is rule-assembled and free. Narrating it costs LLM tokens, so it is a
 * SEPARATE, operator-triggered action (D-016 cost stance — never background spend).
 *
 * Every generated plan writes a `playbook` row into the ledger per actionable block, so
 * GO-3.3 can eventually answer the question no competitor can: "did the plans we wrote
 * actually work?"
 */
class CampaignPlanController extends Controller
{
    public function __construct(
        private readonly PlanGenerator $generator,
        private readonly PlanNarrator $narrator,
        private readonly Ledger $ledger,
    ) {}

    /** The moments a brand could plan for next — the picker. */
    public function moments(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate(['market' => ['nullable', 'string', 'size:2']]);
        $today = CarbonImmutable::now($brand->timezone ?: 'UTC')->toDateString();

        $rows = MarketMoment::query()
            ->when($data['market'] ?? null, fn ($q, $v) => $q->where('market', strtoupper((string) $v)))
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('starts_on')
            ->limit(12)
            ->get()
            ->map(static fn (MarketMoment $m): array => [
                'momentKey' => $m->moment_key,
                'market'    => $m->market,
                'label'     => $m->label,
                'startsOn'  => $m->starts_on->toDateString(),
                'endsOn'    => $m->ends_on->toDateString(),
                'kind'      => $m->kind,
                'year'      => $m->year,
            ])
            ->all();

        return response()->json(['rows' => $rows]);
    }

    public function index(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $rows = CampaignPlan::query()
            ->where('brand_id', $brand->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (CampaignPlan $p): array => [
                'id' => $p->id, 'title' => $p->title, 'momentKey' => $p->moment_key,
                'market' => $p->market, 'year' => $p->year, 'status' => $p->status,
                'hasNarrative' => $p->narrative !== null,
            ])
            ->all();

        return response()->json(['rows' => $rows]);
    }

    public function show(Brand $brand, CampaignPlan $plan): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $plan->brand_id === (int) $brand->id, 404);

        return response()->json($this->payload($plan));
    }

    /** Generate (or regenerate) a plan. Rule-assembled — no LLM involved. */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $data = $request->validate([
            'moment_key' => ['required', 'string', 'max:48'],
            'market'     => ['required', 'string', 'size:2'],
            'year'       => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        $result = $this->generator->generate($brand, $data['moment_key'], $data['market'], $data['year'] ?? null);

        // The refusals are first-class answers, not errors: the caller gets 422 + the reason.
        if ($result['status'] !== 'ok') {
            return response()->json($result, 422);
        }

        $plan = CampaignPlan::updateOrCreate(
            [
                'brand_id'   => $brand->id,
                'moment_key' => $data['moment_key'],
                'market'     => strtoupper($data['market']),
                'year'       => $result['year'],
            ],
            [
                'title'  => $result['title'],
                'blocks' => $result['blocks'],
                'status' => 'draft',
                'created_by_user_id' => Auth::id(),
            ],
        );

        $this->recordToLedger($brand, $plan, $result);

        return response()->json($this->payload($plan) + ['moment' => $result['moment']], 201);
    }

    /** Edit blocks / promote status. The operator owns the plan once it exists. */
    public function update(Request $request, Brand $brand, CampaignPlan $plan): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $plan->brand_id === (int) $brand->id, 404);

        $data = $request->validate([
            'status'    => ['sometimes', 'in:draft,ready,shared'],
            'blocks'    => ['sometimes', 'array'],
            'narrative' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $plan->update($data);

        return response()->json($this->payload($plan->fresh()));
    }

    /** LLM prose. Operator-triggered, key-gated, and it only ever REWRITES the blocks. */
    public function narrate(Brand $brand, CampaignPlan $plan): JsonResponse
    {
        $this->authorize('view', $brand);
        abort_unless((int) $plan->brand_id === (int) $brand->id, 404);

        try {
            $prose = $this->narrator->narrate($plan);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Stored SEPARATELY from the numbers. Prose can never overwrite a figure.
        $plan->update(['narrative' => $prose]);

        return response()->json(['narrative' => $prose]);
    }

    /**
     * One ledger row per ACTIONABLE block, so GO-3.3 can eventually score the plans
     * themselves — "did the plans we wrote actually work?" is a question no competitor
     * can answer about its own advice.
     *
     * @param array<string, mixed> $result
     */
    private function recordToLedger(Brand $brand, CampaignPlan $plan, array $result): void
    {
        $actionable = ['budget' => 'budget_shift', 'creative' => 'creative_refresh', 'channel' => 'budget_shift'];

        foreach ($actionable as $blockName => $kind) {
            $block = $result['blocks'][$blockName] ?? null;
            if ($block === null || ($block['blocked'] ?? false) === true || ($block['entries'] ?? []) === []) {
                continue;   // a blocked block is not advice — do not log it as such
            }

            $this->ledger->record(
                $brand,
                source: 'playbook',
                kind: $kind,
                subjectType: 'brand',
                subjectId: $plan->moment_key . ':' . $plan->market,
                title: $result['title'] . ' — ' . ucfirst($blockName),
                evidence: [
                    'rule'     => 'playbook.' . $blockName,
                    'planId'   => $plan->id,
                    'moment'   => $result['moment'],
                    // The block's numbers, each with its basis — the plan IS the evidence.
                    'entries'  => $block['entries'],
                ],
                confidence: 'solid',
                outcomeMetric: $blockName === 'creative' ? 'roas' : 'revenue',
            );
        }
    }

    /** @return array<string, mixed> */
    private function payload(CampaignPlan $plan): array
    {
        return [
            'id'        => $plan->id,
            'title'     => $plan->title,
            'momentKey' => $plan->moment_key,
            'market'    => $plan->market,
            'year'      => $plan->year,
            'status'    => $plan->status,
            'blocks'    => $plan->blocks,
            'narrative' => $plan->narrative,
            'llmAvailable' => $this->narrator->available(),
            'note'      => 'Every number here is rule-assembled from your own data, the market calendar, or a cited '
                . 'industry constant — each entry shows its basis. The AI narrative (if generated) only rewrites '
                . 'these figures as prose; it never produces a number.',
        ];
    }
}
