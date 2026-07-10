<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LLM narrative drafts for reports (feature spec §6, D-016 ratified
 * 2026-07-10). One row per brand × report type × period+compare selection —
 * regenerating overwrites the draft, operator edits live in edited_blocks.
 * Numbers in the report NEVER come from here (rules own the figures); these
 * are prose blocks only. Additive migration — production is live.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_narratives', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('report_type', 40);
            // e.g. "last30|previous" — the filter selection this draft belongs to.
            $t->string('period_key', 60);

            // Draft from the model: {observations, actions, plan, ideas} — markdown strings.
            $t->json('blocks');
            // Operator's edited copy, same shape. Null until first edit.
            $t->json('edited_blocks')->nullable();

            $t->string('provider', 20);
            $t->string('model', 80);
            $t->string('language', 8)->default('en');
            // The exact data window the draft was generated from.
            $t->date('window_start');
            $t->date('window_end');

            $t->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('generated_at');
            $t->timestampTz('edited_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'report_type', 'period_key'], 'report_narratives_selection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_narratives');
    }
};
