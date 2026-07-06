<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Meta funnel/efficiency fields on daily_metrics for the Ads hub:
 *   - reach              — daily unique users. Summed over a window it's an upper
 *                          bound (the same person on two days counts twice), so a
 *                          windowed frequency is derived as impressions ÷ reach
 *                          and read as an approximation, not a spec figure.
 *   - link_clicks        — Meta inline_link_clicks (clicks to the destination).
 *   - landing_page_views — Meta landing_page_view action (arrived + rendered).
 *
 * These power the purchase funnel (Impressions → Link clicks → Landing views →
 * Purchases) and the efficiency row. Nullable so every existing row is untouched;
 * a row stays null until the live sync or `ads:backfill-spend` re-pulls it, and
 * the funnel renders "not synced yet" (never a fake 0) while it's null.
 * Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t): void {
            $t->unsignedBigInteger('reach')->nullable()->after('conversion_value');
            $t->unsignedInteger('link_clicks')->nullable()->after('reach');
            $t->unsignedInteger('landing_page_views')->nullable()->after('link_clicks');
        });
    }

    public function down(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t): void {
            $t->dropColumn(['reach', 'link_clicks', 'landing_page_views']);
        });
    }
};
