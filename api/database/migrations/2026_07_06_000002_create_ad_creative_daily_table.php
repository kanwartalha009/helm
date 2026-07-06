<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad-level (creative) daily metrics for the Ads hub's Creatives view (Phase D) —
 * one row per (brand, platform, date, ad), the grain needed to rank creatives by
 * ROAS/spend and show a thumbnail. Sits beside daily_metrics (brand×platform×day
 * rollup) and ad_campaign_daily_metrics (campaign grain); never overloads either.
 * Meta today; platform column keeps it agnostic. Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ad_creative_daily', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('platform', 16);              // meta
            $t->date('date');
            $t->string('ad_id', 64);
            $t->string('ad_name', 255)->nullable();
            $t->string('campaign_id', 64)->nullable();
            $t->text('thumbnail_url')->nullable();    // creative{thumbnail_url}; expires — refreshed each sync
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

            $t->unique(['brand_id', 'platform', 'date', 'ad_id'], 'ad_creative_unique');
            $t->index(['brand_id', 'platform', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_creative_daily');
    }
};
