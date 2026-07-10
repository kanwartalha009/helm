<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta's ad relevance diagnostics per creative-day: quality_ranking,
 * engagement_rate_ranking and conversion_rate_ranking, e.g. 'above_average' /
 * 'average' / 'below_average_10'. They ride the existing ad-level insights pull
 * (no extra Meta calls) and power a below-average warning badge on the creative
 * card — a signal, not a headline metric. Nullable because Meta only ranks ads
 * with enough recent impressions ('unknown' is stored as null) and because
 * TikTok creative rows have no equivalent. Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->string('quality_ranking', 32)->nullable()->after('add_to_cart');
            $t->string('engagement_ranking', 32)->nullable()->after('quality_ranking');
            $t->string('conversion_ranking', 32)->nullable()->after('engagement_ranking');
        });
    }

    public function down(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->dropColumn(['quality_ranking', 'engagement_ranking', 'conversion_ranking']);
        });
    }
};
