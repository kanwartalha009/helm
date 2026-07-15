<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1 (monthly-report-v2-mom.md §M1): country tiers as a PLATFORM PRIMITIVE, not a
 * report feature — CountryTiers::resolve() is the ONE definition of "what tier is
 * this country in", reused by the mom report (M2/M3) and, cheaply, anywhere else
 * that shows a country breakdown (dashboard, ads hub, ads-audit).
 *
 * Bosco's real tiers are custom agency-defined labels (T1, T4, US, ASIA, SUMMER, ES,
 * NO, ...), not a fixed 1/2/3 — `tier_key`/`label`/`color` are free text, entirely
 * operator-defined. `countries` is a JSON array of ISO-2 codes; a country absent from
 * every tier row auto-buckets to "Other" at resolve time (never dropped).
 *
 * Same table holds BOTH layers:
 *   - brand_id IS NULL  -> the agency-wide DEFAULT tier set (the fallback/template).
 *   - brand_id = <id>   -> that brand's OVERRIDE set. Resolution: if the brand has
 *     ANY rows, use them exclusively; else fall back to the brand_id-NULL rows.
 * A brand "customizing" its tiers means copying the agency rows into brand-scoped
 * rows client-side, then editing — this migration doesn't special-case that; it's a
 * property of how the write endpoint is used, not the schema.
 *
 * ══ MySQL NULLs-are-distinct trap (3rd+ occurrence: budget_plans.country,
 * anomalies.subject, brand_targets.month) ══
 * `unique(brand_id, tier_key)` with brand_id NULLABLE does NOT stop MySQL from
 * accumulating several agency-default rows with the same tier_key, because MySQL
 * treats every NULL as distinct in a unique index — the exact bug D-025's
 * month_key fix exists to prevent. Same fix here: a generated
 * `brand_key = COALESCE(brand_id, 0)` column, uniqued instead. 0 is a safe sentinel
 * — brand_id is a bigIncrements FK, IDs start at 1.
 *
 * Additive; production is live. workspace_id is the D-022 tenant seam (no FK yet,
 * matching every other seam table) — not part of the uniqueness key: there is no
 * real Workspace model yet (D-022 is seams-only), so brand_id is the only
 * functional discriminator today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_tiers', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)
            $t->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();

            $t->string('tier_key', 24);   // free text, agency-defined (T1, ASIA, SUMMER, ...)
            $t->string('label', 48);
            $t->string('color', 7);       // '#rrggbb'
            $t->json('countries');        // ISO-2 codes, e.g. ["US","CA"]
            $t->unsignedInteger('position')->default(0);

            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->index(['brand_id', 'position']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('country_tiers', function (Blueprint $t): void {
                $t->unsignedBigInteger('brand_key')
                    ->virtualAs('COALESCE(`brand_id`, 0)')
                    ->nullable();
                $t->unique(['brand_key', 'tier_key'], 'country_tiers_brand_key_unique');
            });
        } else {
            // sqlite (tests): an expression index gives the same guarantee.
            DB::statement(
                'CREATE UNIQUE INDEX country_tiers_brand_key_unique '
                . 'ON country_tiers (COALESCE(brand_id, 0), tier_key)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('country_tiers');
    }
};
