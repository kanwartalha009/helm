<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\CreativeDraft;
use App\Services\Creative\CreativeStudioService;
use App\Services\Creative\UnconfirmedStyleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GO-5.1 — Creative testing engine, text-only (master plan §8).
 *
 *  - index()   : list this brand's drafts (brand-visible read).
 *  - generate(): produce + persist draft variants. REFUSES with 422 when the
 *                brand's style isn't confirmed (the §7.4/§8 gate). Admin/manager.
 *  - update()  : operator edit / advance status (approve/export/launch),
 *                forward-only. Admin/manager.
 *  - destroy() : discard a draft. Admin/manager.
 *
 * Nothing here publishes anything — export is GO-5.2, launch-attach is GO-5.3,
 * and pushing to Meta is the separately-gated GO-5b.
 */
class CreativeStudioController extends Controller
{
    public function __construct(private readonly CreativeStudioService $studio)
    {
    }

    public function index(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json(['drafts' => $this->studio->list($brand)->map($this->present(...))->values()]);
    }

    public function generate(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $data = $request->validate([
            'n'      => ['nullable', 'integer', 'min:1', 'max:10'],
            'moment' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $drafts = $this->studio->generate($brand, Auth::id(), (int) ($data['n'] ?? 3), $data['moment'] ?? null);
        } catch (UnconfirmedStyleException $e) {
            // The refusal — a deliberate 422, not an error. The frontend shows
            // "confirm your moodboard first" and links to the style card.
            return response()->json(['message' => $e->getMessage(), 'reason' => 'unconfirmed_style'], 422);
        }

        return response()->json([
            'drafts'    => $drafts->map($this->present(...))->values(),
            'generated' => $drafts->count(),
        ], 201);
    }

    public function update(Request $request, Brand $brand, CreativeDraft $draft): JsonResponse
    {
        $this->authorize('update', $brand);
        abort_unless($draft->brand_id === $brand->id, 404);

        $data = $request->validate([
            'content'        => ['nullable', 'array'],
            'status'         => ['nullable', 'string', 'in:draft,approved,exported,launched'],
            'launchedAdId'   => ['nullable', 'string', 'max:64'],
        ]);

        $updated = $this->studio->update($draft, $data);

        return response()->json(['ok' => true, 'draft' => $this->present($updated)]);
    }

    public function destroy(Brand $brand, CreativeDraft $draft): JsonResponse
    {
        $this->authorize('update', $brand);
        abort_unless($draft->brand_id === $brand->id, 404);

        $this->studio->discard($draft);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function present(CreativeDraft $d): array
    {
        return [
            'id'           => $d->id,
            'modality'     => $d->modality,
            'kind'         => $d->kind,
            'content'      => $d->content,
            'status'       => $d->status,
            'model'        => $d->model,
            'launchedAdId' => $d->launched_ad_id,
            'createdAt'    => optional($d->created_at)->toIso8601String(),
        ];
    }
}
