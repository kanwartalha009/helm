<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-4.3 (master plan §7.3): a generated seasonal campaign plan — the crown jewel.
 *
 * "Here is your Black Friday plan for FR, grounded in YOUR numbers" does not exist as a
 * product anywhere (assessment §2: total whitespace). This table holds those plans.
 *
 * `blocks` is the assembled plan: timeline, budget, channel, creative, measurement. Every
 * entry inside carries its own `basis` — Verified (our data) / Proxy (public signals) /
 * Modeled (a forecast) / Source (a cited constant) — because a plan whose numbers cannot
 * be traced is indistinguishable from one an LLM made up.
 *
 * The blocks are RULE-ASSEMBLED. The LLM only ever rewrites them as prose (see
 * PlanNarrator) — it never produces a figure. `narrative` holds that prose separately
 * from the numbers, so the two can never be confused for one another.
 *
 * Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_plans', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index();  // D-022 seam

            $t->string('moment_key', 48);        // black_friday | three_kings | soldes_hiver …
            $t->char('market', 2);
            $t->unsignedSmallInteger('year');
            $t->string('title', 191);

            $t->string('status', 16)->default('draft');   // draft | ready | shared

            // The rule-assembled plan. Numbers only — each with its basis + source.
            $t->json('blocks');

            // LLM prose, kept SEPARATE from the numbers it describes. Nullable: a plan is
            // complete and usable without it.
            $t->text('narrative')->nullable();

            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            // One plan per (brand, moment, market, year) — regenerating replaces it.
            $t->unique(['brand_id', 'moment_key', 'market', 'year'], 'campaign_plans_unique');
            $t->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_plans');
    }
};
