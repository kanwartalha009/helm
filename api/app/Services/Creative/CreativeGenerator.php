<?php

declare(strict_types=1);

namespace App\Services\Creative;

/**
 * GO-5.1 — the generation SEAM (master plan §8: "build the seam
 * (CreativeGenerator interface), ship text-only"). One implementation exists
 * today — TextCreativeGenerator (copy variants, hook lines, UGC scripts via the
 * LLM). Image and video modalities are a separate, GATED build (they need a
 * Kanwar-approved generation provider + budget, a new dependency); they will
 * implement this same interface and slot in without touching callers.
 *
 * A generator receives an already-ALLOWLISTED CreativeBrief — it never reaches
 * back into the database for brand data, so the "no raw customer data to a
 * model" guarantee is enforced at the brief boundary, once, for every modality.
 */
interface CreativeGenerator
{
    /** 'text' | 'image' | 'video' — which modality this generator produces. */
    public function modality(): string;

    /**
     * Produce up to $n variants from the brief.
     *
     * @return array<int, array{kind: string, content: array<string, mixed>}>
     *   each item's kind is one of CreativeDraft::KINDS
     */
    public function generate(CreativeBrief $brief, int $n): array;

    /** The concrete model id used (stored on every draft for provenance). Null when N/A. */
    public function modelId(): ?string;
}
