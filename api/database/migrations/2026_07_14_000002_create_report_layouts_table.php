<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1 (monthly-report-v2-mom.md §M1 + REV2 R2): the report customizer's storage.
 * `sections` is the ordered layout: json array of
 * {key, enabled, position, view: 'chart'|'table'|'both', settings?} (REV2 R2 added
 * `view` to the spec's original {key,enabled,position} shape).
 *
 * Same table holds BOTH layers, same override semantics as country_tiers:
 *   - brand_id IS NULL  -> the agency-wide DEFAULT layout for that report_type.
 *   - brand_id = <id>   -> that brand's OVERRIDE layout.
 * Resolution (ReportLayouts::resolve()): brand override -> agency default ->
 * code default (config/momreport.php's 'sections' catalog, for report_type='mom').
 *
 * Shares snapshot the RESOLVED layout into the share's filters json at share-creation
 * time (ReportController::createShare, unchanged by this migration) — a share never
 * reshuffles when the agency later re-customizes the live layout.
 *
 * ══ Same MySQL NULLs-are-distinct trap as country_tiers ══ (see that migration's
 * docblock). `unique(brand_id, report_type)` with brand_id nullable would let MySQL
 * accumulate several agency-default rows per report_type. Fixed with the same
 * generated `brand_key = COALESCE(brand_id, 0)` pattern.
 *
 * Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_layouts', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)
            $t->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();

            $t->string('report_type', 24); // 'mom' today; schema is report-type-agnostic
            $t->json('sections');          // ordered [{key,enabled,position,view,settings?}]

            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->index(['brand_id', 'report_type']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('report_layouts', function (Blueprint $t): void {
                $t->unsignedBigInteger('brand_key')
                    ->virtualAs('COALESCE(`brand_id`, 0)')
                    ->nullable();
                $t->unique(['brand_key', 'report_type'], 'report_layouts_brand_key_unique');
            });
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX report_layouts_brand_key_unique '
                . 'ON report_layouts (COALESCE(brand_id, 0), report_type)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_layouts');
    }
};
