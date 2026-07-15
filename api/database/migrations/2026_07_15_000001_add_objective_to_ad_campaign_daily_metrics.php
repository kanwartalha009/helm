<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mom S16 (monthly-report-v2-mom.md §M3 — "Thruplay/awareness country
 * concentration ... campaign objective from ad_campaign_daily_metrics").
 * S16 was left deliberately unregistered through M3/M5 because this column
 * didn't exist anywhere in the schema (verified by grep at the time) — this
 * is that gap being closed.
 *
 * `objective` — Meta's campaign objective string (e.g. 'OUTCOME_AWARENESS'
 * under the post-2022 Outcome-Driven Ads Experience taxonomy, or a legacy
 * pre-ODAX value like 'BRAND_AWARENESS'/'REACH' on older campaigns Meta
 * hasn't migrated). Meta-only for now — Google/TikTok's fetchCampaignRange
 * implementations don't emit an 'objective' key, so their rows stay null
 * (missing, never a guessed value), same convention as `channel_type` being
 * Google-only in the migration beside this one. Nullable, additive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_campaign_daily_metrics', function (Blueprint $t): void {
            $t->string('objective', 64)->nullable()->after('channel_type');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaign_daily_metrics', function (Blueprint $t): void {
            $t->dropColumn('objective');
        });
    }
};
