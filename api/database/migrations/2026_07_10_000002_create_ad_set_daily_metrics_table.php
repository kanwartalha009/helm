<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (spec §4 Phase 3): the middle layer of the ad hierarchy Helm was blind
 * to — Meta ad sets, Google ad groups (+ PMax asset groups), TikTok ad groups.
 * One row per (brand, platform, date, ad_set_id). Additive; production is live.
 *
 * Budget / learning / status are POINT-IN-TIME snapshots taken at sync time (the
 * platform APIs give no budget history) — a row synced yesterday shows yesterday's
 * budget. Any UI rendering budget must say "as of last sync".
 *
 * Missing ≠ zero: reach/frequency are Meta-only and stay NULL elsewhere (never 0);
 * search impression-share metrics are Google-Search-only and null everywhere else.
 * `entity_kind` distinguishes a normal ad set/group from a PMax asset_group (which
 * has no budget of its own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_set_daily_metrics', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('platform', 16);              // meta | google | tiktok
            $t->date('date');                        // brand timezone

            $t->string('ad_set_id', 64);
            $t->string('ad_set_name', 255)->nullable();
            $t->string('campaign_id', 64)->nullable();
            $t->string('entity_kind', 16)->default('ad_set'); // ad_set | asset_group (Google PMax)

            $t->string('status', 32)->nullable();            // platform-native effective status
            $t->string('learning_status', 16)->nullable();   // meta: LEARNING|SUCCESS|FAIL; others null
            $t->decimal('daily_budget', 14, 2)->nullable();  // native currency, point-in-time snapshot
            $t->decimal('lifetime_budget', 14, 2)->nullable();

            $t->decimal('spend', 14, 2)->default(0);
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->unsignedBigInteger('reach')->nullable();     // meta only; NULL elsewhere, never 0
            $t->decimal('frequency', 8, 4)->nullable();      // meta only
            $t->unsignedInteger('conversions')->default(0);
            $t->decimal('conversion_value', 14, 2)->default(0);

            $t->decimal('search_impression_share', 6, 4)->nullable();  // google ad groups only
            $t->decimal('search_budget_lost_is', 6, 4)->nullable();    // google ad groups only

            $t->string('currency', 8)->nullable();
            $t->decimal('fx_rate_to_usd', 14, 8)->nullable();
            $t->boolean('is_complete')->default(true);
            $t->timestampTz('pulled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'platform', 'date', 'ad_set_id'], 'ad_set_unique');
            $t->index(['brand_id', 'platform', 'date']);
            $t->index(['brand_id', 'campaign_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_set_daily_metrics');
    }
};
