<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-4.4 — Moodboard / brand style (master plan §7.4). One style profile per
 * brand: the palette, tone words, do/don't guidance and reference winners that
 * GO-5's creative generation is grounded in.
 *
 * The whole point of §7.4 is the CONFIRM GATE: a style is `draft` until an
 * operator has reviewed and confirmed it (`status='confirmed'`, `confirmed_by`
 * set). GO-5 REFUSES to generate against an unconfirmed style — an
 * auto-extracted palette + an LLM-drafted tone are suggestions, never truth,
 * until a human signs off. Nothing here is generated numbers; palette comes
 * from dominant-colour binning of the brand's own winning-creative thumbnails
 * (pure-PHP GD), tone is LLM prose over the brand's own product copy (D-016,
 * key-gated, always operator-edited).
 *
 * Additive, non-destructive. One row per brand (unique brand_id) — this is a
 * current-state profile, not a time series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_styles', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam

            // palette:   [{hex:'#RRGGBB', weight: 0.0-1.0}]  (dominant colours)
            // toneWords: ['warm','confident',...]            (voice descriptors)
            // doDont:    {do:[...], dont:[...]}              (guidance bullets)
            // refs:      [{adId, thumbnailUrl, roas, note}]  (reference winners / saved ads)
            $t->json('palette')->nullable();
            $t->json('tone_words')->nullable();
            $t->json('do_dont')->nullable();
            $t->json('refs')->nullable();

            // draft (suggestions, GO-5 refuses) → confirmed (operator signed off).
            $t->string('status', 16)->default('draft');
            $t->unsignedBigInteger('confirmed_by')->nullable(); // users.id; null while draft
            $t->timestampTz('confirmed_at')->nullable();

            $t->timestampsTz();

            $t->unique('brand_id', 'brand_styles_brand_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_styles');
    }
};
