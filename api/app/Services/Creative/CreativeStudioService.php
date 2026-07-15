<?php

declare(strict_types=1);

namespace App\Services\Creative;

use App\Models\Brand;
use App\Models\CreativeDraft;
use App\Models\ProductCatalog;
use App\Services\BrandStyleService;
use Illuminate\Support\Collection;

/**
 * GO-5.1 — the creative testing engine's orchestrator (master plan §8).
 *
 * The doctrine is a variation engine inside a HUMAN LOOP. This service:
 *   1. REFUSES to generate unless the brand's style is CONFIRMED (GO-4.4 gate) —
 *      throws UnconfirmedStyleException, and makes NO LLM call.
 *   2. Assembles an ALLOWLISTED CreativeBrief from confirmed style + product
 *      facts + proven-hook benchmarks + moment — never customer data.
 *   3. Runs the modality generator (text now; image/video gated) and persists
 *      each variant as a `draft` for the operator to review, approve or discard.
 *
 * Nothing is auto-approved and nothing is ever published — that stays a
 * deliberate operator action (GO-5.2 export, GO-5.3 launch attach).
 */
final class CreativeStudioService
{
    public function __construct(
        private readonly BrandStyleService $styles,
        private readonly TextCreativeGenerator $textGenerator,
    ) {
    }

    /**
     * Generate + persist draft variants. Refuses (throws) when the brand has no
     * confirmed style.
     *
     * @return Collection<int, CreativeDraft>
     */
    public function generate(Brand $brand, ?int $userId, int $n = 3, ?string $momentLabel = null): Collection
    {
        $style = $this->styles->confirmed($brand);
        if ($style === null) {
            throw new UnconfirmedStyleException();
        }

        $brief = $this->buildBrief($brand, $style, $momentLabel);
        $variants = $this->textGenerator->generate($brief, $n);
        $model = $this->textGenerator->modelId();

        $drafts = collect();
        foreach ($variants as $v) {
            if (! in_array($v['kind'] ?? null, CreativeDraft::KINDS, true)) {
                continue;
            }
            $drafts->push(CreativeDraft::create([
                'brand_id'     => $brand->id,
                'workspace_id' => $brand->workspace_id ?? null,
                'modality'     => $this->textGenerator->modality(),
                'kind'         => $v['kind'],
                'content'      => $v['content'],
                'status'       => 'draft',
                'model'        => $model,
                'created_by'   => $userId,
            ]));
        }

        return $drafts;
    }

    /** @return Collection<int, CreativeDraft> */
    public function list(Brand $brand): Collection
    {
        return CreativeDraft::query()
            ->where('brand_id', $brand->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Operator edit / status transition. Content edits are always allowed;
     * status may only advance along the lifecycle (draft → approved → exported →
     * launched), never backwards — a launched draft's provenance is fixed.
     */
    public function update(CreativeDraft $draft, array $data): CreativeDraft
    {
        if (array_key_exists('content', $data) && is_array($data['content'])) {
            $draft->content = $data['content'];
        }

        if (isset($data['status']) && $this->canTransition((string) $draft->status, (string) $data['status'])) {
            $draft->status = $data['status'];
        }

        if (array_key_exists('launchedAdId', $data)) {
            $draft->launched_ad_id = $data['launchedAdId'] !== null ? (string) $data['launchedAdId'] : null;
        }

        $draft->save();

        return $draft;
    }

    public function discard(CreativeDraft $draft): void
    {
        $draft->delete();
    }

    /** Forward-only lifecycle; same state is a no-op, backwards is rejected. */
    private function canTransition(string $from, string $to): bool
    {
        $order = array_flip(CreativeDraft::STATUSES);
        if (! isset($order[$from], $order[$to])) {
            return false;
        }

        return $order[$to] >= $order[$from];
    }

    private function buildBrief(Brand $brand, \App\Models\BrandStyle $style, ?string $momentLabel): CreativeBrief
    {
        // Product FACTS only — name, type, stock. Price is not stored anywhere in
        // this codebase yet (product_costs is COGS, not sale price), so it is
        // honestly omitted rather than guessed. Top by stock as a stand-in for
        // "what we can actually sell right now".
        $products = ProductCatalog::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('title')
            ->orderByDesc('total_inventory')
            ->limit(8)
            ->get()
            ->map(static fn (ProductCatalog $p): array => [
                'name'  => (string) $p->title,
                'type'  => $p->product_type !== null ? (string) $p->product_type : null,
                'stock' => (int) $p->total_inventory,
            ])
            ->all();

        return new CreativeBrief(
            brandName: (string) $brand->name,
            toneWords: array_values($style->tone_words ?? []),
            paletteHex: array_values(array_map(
                static fn ($s): string => (string) ($s['hex'] ?? ''),
                array_filter($style->palette ?? [], static fn ($s): bool => is_array($s) && isset($s['hex'])),
            )),
            doDont: [
                'do'   => array_values(($style->do_dont['do'] ?? [])),
                'dont' => array_values(($style->do_dont['dont'] ?? [])),
            ],
            products: $products,
            // Proven-hook benchmarks (ads-library Phase 4.3) are wired in a
            // follow-up when a brand has a tagged internal corpus; empty here is
            // honest, not a blocker — the brief still grounds on style + products.
            hookBenchmarks: [],
            momentLabel: $momentLabel,
            currency: $brand->base_currency ?: 'USD',
        );
    }
}
