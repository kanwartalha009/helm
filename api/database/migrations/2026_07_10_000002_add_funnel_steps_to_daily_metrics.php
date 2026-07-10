<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive mid-funnel commerce steps on daily_metrics for the Ads hub funnel:
 *   - add_to_carts        — Meta add-to-cart actions (omni_add_to_cart family).
 *   - checkouts_initiated — Meta initiate-checkout actions (omni_initiated_checkout).
 *
 * Both come from the `actions` payload the daily insights call already requests,
 * so filling them costs no extra API call. Nullable so every existing row is
 * untouched; a row stays null until the live sync or `ads:backfill-spend`
 * re-pulls it, and the funnel simply omits the step while it's null (missing
 * data is not zero). Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t): void {
            $t->unsignedInteger('add_to_carts')->nullable()->after('landing_page_views');
            $t->unsignedInteger('checkouts_initiated')->nullable()->after('add_to_carts');
        });
    }

    public function down(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t): void {
            $t->dropColumn(['add_to_carts', 'checkouts_initiated']);
        });
    }
};
