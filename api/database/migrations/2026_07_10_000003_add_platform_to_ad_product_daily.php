<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen ad_product_daily from Meta-only to all three ad platforms (spec §4 Phase
 * 5). The table shipped with NO platform column and a unique key of
 * (brand_id, date, product_key) — verified 2026-07-10. This adds `platform`
 * (default 'meta' so every existing row is correctly labelled) and swaps the
 * unique key to include it, so Google + TikTok can attribute the same product
 * handle on the same day without colliding with the Meta row.
 *
 * Additive + non-destructive: the column has a default (no row rewrite of
 * values), and an INDEX swap moves no data — the existing Meta rows keep their
 * spend untouched. Production is live; this is safe to run forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Add the column first, defaulted so live Meta rows become platform='meta'.
        Schema::table('ad_product_daily', function (Blueprint $t): void {
            $t->string('platform', 16)->default('meta')->after('brand_id');
        });

        // 2) Swap the unique index to include platform (index change touches no
        //    row data). Drop the old one by its name, add the platform-scoped one.
        Schema::table('ad_product_daily', function (Blueprint $t): void {
            $t->dropUnique('ad_product_unique');
            $t->unique(['brand_id', 'platform', 'date', 'product_key'], 'ad_product_platform_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ad_product_daily', function (Blueprint $t): void {
            $t->dropUnique('ad_product_platform_unique');
            $t->unique(['brand_id', 'date', 'product_key'], 'ad_product_unique');
        });
        Schema::table('ad_product_daily', function (Blueprint $t): void {
            $t->dropColumn('platform');
        });
    }
};
