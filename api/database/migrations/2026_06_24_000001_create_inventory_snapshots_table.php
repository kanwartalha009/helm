<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory snapshots for the reporting engine's dead-stock analysis (feature
 * spec slice 2.1 — commerce intelligence). One row per (brand, captured_on,
 * dimension, key) holding stock-on-hand + units sold + sell-through over a
 * trailing window, BY PRODUCT and BY COLLECTION (Shopify product_type). The
 * dead-inventory report reads the latest snapshot per brand.
 *
 * Distinct from commerce_daily_metrics (daily SALES) — inventory is a
 * point-in-time stock level, not a daily flow. Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_snapshots', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->date('captured_on');
            $t->string('dimension_type', 16);    // product | collection
            $t->string('dimension_key', 191);
            $t->string('dimension_label', 191)->nullable();
            $t->integer('ending_units')->nullable();       // stock on hand at window end
            $t->integer('units_sold')->nullable();         // units sold across the window
            $t->decimal('sell_through_rate', 8, 4)->nullable(); // ShopifyQL sell_through_rate (as returned)
            $t->unsignedSmallInteger('window_days')->default(90);
            $t->timestampTz('pulled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'captured_on', 'dimension_type', 'dimension_key'], 'inventory_snapshot_unique');
            $t->index(['brand_id', 'dimension_type', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
    }
};
