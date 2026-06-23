<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Granular Shopify commerce metrics for the reporting engine (feature spec
 * slice 2.1) — sales broken down BY COUNTRY, BY PRODUCT, and BY CATEGORY
 * (product_type), one row per (brand, date, dimension_type, dimension_key).
 * Powers the Country and Product reports. Kept beside the polymorphic
 * daily_metrics (which stays the brand×platform×day rollup), never overloading
 * it. Additive, non-destructive — new table only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commerce_daily_metrics', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->date('date');
            $t->string('dimension_type', 16);    // country | product | category
            $t->string('dimension_key', 191);    // billing_country name / product title / product_type
            $t->string('dimension_label', 191)->nullable();
            $t->integer('orders')->nullable();
            $t->integer('units')->nullable();
            $t->decimal('net_sales', 14, 2)->nullable();
            $t->decimal('total_sales', 14, 2)->nullable();
            $t->decimal('refunds_amount', 14, 2)->nullable();
            $t->string('currency', 8)->nullable();
            $t->decimal('fx_rate_to_usd', 14, 8)->nullable();
            $t->boolean('is_complete')->default(true);
            $t->timestampTz('pulled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'date', 'dimension_type', 'dimension_key'], 'commerce_dim_unique');
            $t->index(['brand_id', 'dimension_type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_daily_metrics');
    }
};
