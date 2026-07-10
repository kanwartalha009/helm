<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google campaign enrichment on ad_campaign_daily_metrics (the existing
 * `status` column starts being filled by the same pull):
 *   - channel_type             — advertising_channel_type ('search', 'shopping',
 *                                'performance_max', …) so channel certainty no
 *                                longer depends on campaign naming conventions.
 *   - all_conversions          — Google's all-conversions total (incl. cross-device
 *                                and view-assisted), beside the primary conversions.
 *   - view_through_conversions — impressions that converted without a click.
 *   - search_impression_share  — share of eligible Search/Shopping impressions won
 *                                (fraction 0–1; Google floors sub-10% at 0.0999).
 *   - search_budget_lost_is    — impression share lost purely to budget (fraction).
 *
 * All nullable: Meta/TikTok rows never fill them, and the impression-share pair
 * only exists for Search/Shopping campaigns — the drawer hides a null row rather
 * than showing 0. Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_campaign_daily_metrics', function (Blueprint $t): void {
            $t->string('channel_type', 32)->nullable()->after('status');
            $t->decimal('all_conversions', 14, 2)->nullable()->after('conversion_value');
            $t->unsignedInteger('view_through_conversions')->nullable()->after('all_conversions');
            $t->decimal('search_impression_share', 6, 4)->nullable()->after('view_through_conversions');
            $t->decimal('search_budget_lost_is', 6, 4)->nullable()->after('search_impression_share');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaign_daily_metrics', function (Blueprint $t): void {
            $t->dropColumn(['channel_type', 'all_conversions', 'view_through_conversions', 'search_impression_share', 'search_budget_lost_is']);
        });
    }
};
