<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\BrandStyleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GO-4.4 — Moodboard / brand style (master plan §7.4).
 *
 *  - show()    : the current profile (saved, or a scaffold with live winners).
 *                Brand-visible read.
 *  - suggest() : run the expensive, best-effort SUGGESTION step — palette from
 *                winner thumbnails + LLM-drafted tone. Returns suggestions for
 *                the operator to review; saves nothing. Admin/manager only (it
 *                spends LLM tokens and does real work), same gate as writes.
 *  - store()   : save the operator-reviewed style; `confirm:true` flips it to
 *                confirmed (the §7.4 review gate GO-5 depends on). Admin/manager.
 *
 * RBAC mirrors CountryTierController/BrandTargetController: reading is
 * brand-visible, writing is master_admin|manager (route-gated).
 */
class BrandStyleController extends Controller
{
    public function __construct(private readonly BrandStyleService $styles)
    {
    }

    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return response()->json($this->styles->resolve($brand));
    }

    public function suggest(Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        return response()->json([
            'palette'   => $this->styles->suggestPalette($brand),
            'toneWords' => $this->styles->draftTone($brand),
            'winners'   => $this->styles->winners($brand),
        ]);
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $data = $request->validate([
            'palette'             => ['nullable', 'array', 'max:24'],
            'palette.*.hex'       => ['required_with:palette', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'palette.*.weight'    => ['nullable', 'numeric'],
            'toneWords'           => ['nullable', 'array', 'max:16'],
            'toneWords.*'         => ['string', 'max:24'],
            'doDont'              => ['nullable', 'array'],
            'doDont.do'           => ['nullable', 'array', 'max:20'],
            'doDont.do.*'         => ['string', 'max:160'],
            'doDont.dont'         => ['nullable', 'array', 'max:20'],
            'doDont.dont.*'       => ['string', 'max:160'],
            'refs'                => ['nullable', 'array', 'max:24'],
            'confirm'             => ['nullable', 'boolean'],
        ]);

        $style = $this->styles->save(
            $brand,
            [
                'palette'   => $data['palette'] ?? null,
                'toneWords' => $data['toneWords'] ?? null,
                'doDont'    => $data['doDont'] ?? null,
                'refs'      => $data['refs'] ?? null,
            ],
            Auth::id(),
            (bool) ($data['confirm'] ?? false),
        );

        return response()->json([
            'ok'     => true,
            'status' => $style->status,
            'style'  => $this->styles->resolve($brand),
        ], 201);
    }
}
