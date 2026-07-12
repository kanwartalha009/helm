<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bosco item A (spec bosco-inputs-2026-07-12.md §A.1): `brand_targets.month` becomes
 * NULLABLE — a null month is the brand's STANDING DEFAULT goal, applying to every month
 * that has no explicit override. Per-month overrides stay possible (GO-2 §5.1 will use
 * them); the v1 UI only ever writes the standing default.
 *
 * ══ WHY THIS IS NOT JUST `->nullable()` ══
 * The spec asks for `month` nullable + `unique (brand_id, month)`. On MySQL — which is
 * what production runs (D-001) — **NULLs are DISTINCT in a unique index**. That unique
 * key therefore does NOT stop a brand from accumulating several standing-default rows,
 * and pacing would then pick one arbitrarily: a brand could silently carry two conflicting
 * revenue targets, and nobody would know which one the client was being graded against.
 *
 * So the intent (one standing default per brand) is enforced with a generated column:
 *     month_key = COALESCE(month, '__default')
 * and unique (brand_id, month_key). Same pattern already ratified in D-024 for
 * platform_credentials. Identical behaviour to the spec, minus the silent duplicate bug.
 *
 * Additive + non-destructive: existing month-specific rows keep their exact meaning
 * (month_key = their month); no data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The old key cannot coexist with the new one (same columns, weaker guarantee).
        Schema::table('brand_targets', function (Blueprint $t): void {
            $t->dropUnique('brand_targets_unique');
        });

        Schema::table('brand_targets', function (Blueprint $t): void {
            // null = the standing default that applies to every un-overridden month.
            $t->string('month', 7)->nullable()->change();
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('brand_targets', function (Blueprint $t): void {
                $t->string('month_key', 12)
                    ->virtualAs("COALESCE(`month`, '__default')")
                    ->nullable();
                $t->unique(['brand_id', 'month_key'], 'brand_targets_month_key_unique');
            });
        } else {
            // sqlite (tests): an expression index gives the same guarantee.
            DB::statement(
                'CREATE UNIQUE INDEX brand_targets_month_key_unique '
                . "ON brand_targets (brand_id, COALESCE(month, '__default'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('brand_targets', function (Blueprint $t): void {
                $t->dropUnique('brand_targets_month_key_unique');
            });
            Schema::table('brand_targets', function (Blueprint $t): void {
                $t->dropColumn('month_key');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS brand_targets_month_key_unique');
        }

        Schema::table('brand_targets', function (Blueprint $t): void {
            $t->unique(['brand_id', 'month'], 'brand_targets_unique');
        });
    }
};
