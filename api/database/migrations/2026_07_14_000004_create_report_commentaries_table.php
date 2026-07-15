<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2 (monthly-report-v2-mom.md §M2): "own endpoint, commentary + To-Do editable
 * blocks (stored per brand+month+section in report_commentaries, carried into
 * shares)" — every mom section gets one row per (brand, month, section_key).
 *
 * `commentary` is the free-text annotation box; `todo` is a json array of
 * {text, done} the agency checks off per section (distinct from the report-wide
 * S0 Next Steps carryover in report_next_steps — that's a whole-report list with
 * status/group/carried_from; this is a lightweight per-section note).
 *
 * Additive; production is live. No nullable-column unique-key trap here — every
 * column in the natural key (brand_id, month, section_key) is required, not
 * nullable, so a plain composite unique index is correct (unlike country_tiers/
 * report_layouts, which needed the generated-column workaround for their
 * nullable brand_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_commentaries', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();

            $t->string('report_type', 24)->default('mom');
            $t->string('month', 7);        // 'Y-m', brand-tz calendar month
            $t->string('section_key', 24); // e.g. 'S1', 'S-EX'

            $t->text('commentary')->nullable();
            $t->json('todo')->nullable();  // [{text, done}]

            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->unique(['brand_id', 'report_type', 'month', 'section_key'], 'report_commentaries_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_commentaries');
    }
};
