<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4 (monthly-report-v2-mom.md §M4) — "S19 Novedades (slide 31). Agency-wide
 * monthly talking points (workspace-level report_notes per month, written once
 * in Settings, appears in every brand's report that month, per-brand editable
 * copy)."
 *
 * Same override layering as country_tiers/report_layouts:
 *   - brand_id IS NULL  -> the agency-wide DEFAULT note for that month (written
 *                          once in Settings -> "Novedades").
 *   - brand_id = <id>   -> that brand's own EDITED COPY for that month, which
 *                          replaces the default in THAT brand's report only.
 * Resolution (Novedades::resolve()): brand copy -> agency default -> absent
 * (no code default — this is pure editorial content, never fabricated).
 *
 * ══ Same MySQL NULLs-are-distinct trap as country_tiers/report_layouts ══ —
 * `unique(brand_id, month)` with brand_id nullable would let MySQL accumulate
 * several agency-default rows per month. Fixed with the same generated
 * `brand_key = COALESCE(brand_id, 0)` pattern (see report_layouts' docblock).
 *
 * Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_notes', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)
            $t->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();

            $t->string('month', 7); // 'Y-m', brand-tz calendar month
            $t->text('body');       // the novedades text itself

            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->index(['brand_id', 'month']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('report_notes', function (Blueprint $t): void {
                $t->unsignedBigInteger('brand_key')
                    ->virtualAs('COALESCE(`brand_id`, 0)')
                    ->nullable();
                $t->unique(['brand_key', 'month'], 'report_notes_brand_key_unique');
            });
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX report_notes_brand_key_unique '
                . 'ON report_notes (COALESCE(brand_id, 0), month)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_notes');
    }
};
