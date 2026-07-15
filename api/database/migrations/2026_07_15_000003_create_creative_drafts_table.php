<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-5.1 — Creative testing engine, TEXT ONLY (master plan §8). One row per
 * generated-then-operator-reviewed creative asset: a copy variant, a hook line,
 * or a UGC shot-script.
 *
 * Doctrine (§8, U4): a variation engine inside a HUMAN LOOP, never a turnkey
 * ad-maker. Everything here starts life as `draft` (a suggestion) and only an
 * operator moves it forward. The lifecycle is:
 *   draft → approved → exported (GO-5.2) → launched (operator attaches the real
 *   ad id, GO-5.3 measures it).
 *
 * `model` records which LLM produced the draft (§8: "stored with every generated
 * draft") so the eventual AI-vs-human comparison is honest about provenance.
 * `plan_id` / `brief_id` are the optional origin (a plan's creative block or an
 * ads-library brief) — nullable because a draft can be generated ad-hoc.
 *
 * Image/video modalities are a SEPARATE, gated build (a Kanwar-approved
 * generation provider); this table's `content` json is modality-agnostic so they
 * slot in without a schema change. Additive, non-destructive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_drafts', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam

            $t->unsignedBigInteger('plan_id')->nullable();   // campaign_plans.id — optional origin
            $t->string('brief_id', 64)->nullable();          // ads-library brief ref — optional origin

            $t->string('modality', 16)->default('text');     // text (now) | image | video (gated, later)
            $t->string('kind', 24);                          // copy | hook | ugc_script
            $t->json('content');                             // {headline?, body?, text?, script?...} — modality-agnostic
            $t->string('status', 16)->default('draft');      // draft | approved | exported | launched
            $t->string('model', 64)->nullable();             // LLM model id that produced it (provenance)

            // GO-5.3 loop: the real ad this draft became, attached by the operator.
            $t->string('launched_ad_id', 64)->nullable();

            $t->unsignedBigInteger('created_by')->nullable(); // users.id
            $t->timestampsTz();

            $t->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_drafts');
    }
};
