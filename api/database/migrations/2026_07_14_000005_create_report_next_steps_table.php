<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4 (monthly-report-v2-mom.md §M4) — "S0 Next Steps carryover (slide 3 +
 * 28-29). report_next_steps (brand_id, month, items json [{text, group
 * ('mes'|'ads'|'countries'|'email'|'cro'), status open|done|dropped,
 * carried_from}]). Generating month M pre-fills from M-1's open items (the
 * PDF's 'copiar y pegar para ver status' — automated)."
 *
 * One row per (brand, month) — `items` holds the whole checklist as a json
 * array, not one row per item; the checklist is always edited/saved as a
 * whole (SNextStepsSection's build() computes the M-1 carry-forward READ-ONLY
 * when no row exists yet for month M — a GET never writes — so a missing row
 * is a normal, expected state for a month nobody has edited, not an error).
 *
 * brand_id is always required here (S0 has no agency-wide default layer,
 * unlike report_notes/S19) so a plain composite unique index is correct —
 * no nullable-column COALESCE workaround needed (see report_layouts'
 * docblock for when that trick IS needed).
 *
 * Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_next_steps', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();

            $t->string('month', 7); // 'Y-m', brand-tz calendar month
            $t->json('items');      // [{text, group, status, carried_from}]

            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->unique(['brand_id', 'month'], 'report_next_steps_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_next_steps');
    }
};
