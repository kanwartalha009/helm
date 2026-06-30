<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ad spend broken down by a segment dimension — the "Audience" dashboard
 * view (feature: audience-segment spend). One row per (brand, date,
 * breakdown_type, segment), where breakdown_type is the axis the operator
 * picked: audience (ASC new/engaged/existing/unknown), age_gender, placement,
 * country, device. Generic so a new breakdown is data, not schema.
 *
 * Sits beside daily_metrics (the brand×platform×day rollup) and the
 * campaign-level table — never overloads either. Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meta_breakdown_daily', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->date('date');
            $t->string('breakdown_type', 24);    // audience | age_gender | placement | country | device
            $t->string('segment_key', 191);      // e.g. new_customers | 25-34 · female | instagram · feed
            $t->string('segment_label', 191)->nullable();
            $t->decimal('spend', 14, 2)->default(0);
            $t->unsignedBigInteger('impressions')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->unsignedInteger('conversions')->default(0);       // attributed purchases (7d_click)
            $t->decimal('conversion_value', 14, 2)->default(0);
            $t->string('currency', 8)->nullable();
            $t->decimal('fx_rate_to_usd', 14, 8)->nullable();
            $t->boolean('is_complete')->default(true);
            $t->timestampTz('pulled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'date', 'breakdown_type', 'segment_key'], 'meta_breakdown_unique');
            $t->index(['brand_id', 'breakdown_type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_breakdown_daily');
    }
};
