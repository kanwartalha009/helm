<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign-level ad metrics for the reporting engine's Meta + Google ads audit
 * (feature spec slice 2.2 / 2.4) — one row per (brand, platform, date,
 * campaign), the grain the audit needs to rank winners, flag waste, and build
 * the kill-list + strategy. Sits beside daily_metrics (which stays the
 * brand×platform×day rollup the dashboard uses); this never overloads it.
 * Additive, non-destructive — new table only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ad_campaign_daily_metrics', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('platform', 16);            // meta | google | tiktok
            $t->date('date');
            $t->string('campaign_id', 64);
            $t->string('campaign_name', 255)->nullable();
            $t->string('status', 16)->nullable();  // active | paused | … when the API gives it
            $t->decimal('spend', 14, 2)->default(0);
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->unsignedInteger('conversions')->default(0);       // attributed purchases (7d_click)
            $t->decimal('conversion_value', 14, 2)->default(0);   // attributed purchase value
            $t->string('currency', 8)->nullable();
            $t->decimal('fx_rate_to_usd', 14, 8)->nullable();
            $t->boolean('is_complete')->default(true);
            $t->timestampTz('pulled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'platform', 'date', 'campaign_id'], 'ad_campaign_unique');
            $t->index(['brand_id', 'platform', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_daily_metrics');
    }
};
