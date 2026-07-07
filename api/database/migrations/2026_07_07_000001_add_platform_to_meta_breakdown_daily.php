<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalize meta_breakdown_daily to hold breakdowns for any ad platform (the
 * table's docblock already says "generic so a new breakdown is data, not
 * schema"). Adds a `platform` column (existing rows = 'meta') and folds it into
 * the unique key so a Meta and a TikTok segment with the same key (e.g. country
 * "ES") for one brand-day don't collide on upsert. Additive + data-preserving.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meta_breakdown_daily', function (Blueprint $t): void {
            $t->string('platform', 16)->default('meta')->after('brand_id');
        });

        Schema::table('meta_breakdown_daily', function (Blueprint $t): void {
            $t->dropUnique('meta_breakdown_unique');
            $t->unique(['brand_id', 'platform', 'date', 'breakdown_type', 'segment_key'], 'breakdown_platform_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meta_breakdown_daily', function (Blueprint $t): void {
            $t->dropUnique('breakdown_platform_unique');
            $t->unique(['brand_id', 'date', 'breakdown_type', 'segment_key'], 'meta_breakdown_unique');
        });

        Schema::table('meta_breakdown_daily', function (Blueprint $t): void {
            $t->dropColumn('platform');
        });
    }
};
