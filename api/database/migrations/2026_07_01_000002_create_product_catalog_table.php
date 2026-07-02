<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current Shopify product catalog per brand — the stock + variant-count + handle
 * side of the Inventory Intelligence report, and the bridge that joins ad spend
 * (ad_product_daily, keyed by product handle) to commerce (commerce_daily_metrics,
 * keyed by product title). A snapshot, refreshed by shopify:sync-catalog — one row
 * per (brand, handle). Additive, non-destructive.
 *
 * `handle` is stored lower-cased so it matches the handles parsed from ad landing
 * URLs. `variants` holds a compact list [{t: title, q: qty}] for the expandable
 * per-variant stock rows; variant_count / total_inventory are the summary columns.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_catalog', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('product_id', 64)->nullable();     // Shopify GID
            $t->string('handle', 191);
            $t->string('title', 255)->nullable();
            $t->string('product_type', 191)->nullable();
            $t->string('status', 16)->nullable();
            $t->json('tags')->nullable();
            $t->unsignedInteger('variant_count')->default(0);
            $t->integer('total_inventory')->default(0);    // can be negative (oversold)
            $t->json('variants')->nullable();              // [{t: variant title, q: inventory qty}]
            $t->timestampTz('captured_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'handle'], 'product_catalog_unique');
            $t->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalog');
    }
};
