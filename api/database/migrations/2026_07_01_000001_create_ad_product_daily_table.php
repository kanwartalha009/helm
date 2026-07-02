<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ad spend attributed to a Shopify PRODUCT, per (brand, date, product_key) —
 * the spend/ROAS/ads backbone of the Inventory Intelligence report (feature spec
 * brand-inventory-and-customer-mix-reports.md §3).
 *
 * product_key is the Shopify product handle parsed from the ad's landing URL
 * (market prefix stripped, so /en/products/x and /products/x combine). Two
 * reserved keys preserve the non-product spend rather than dropping it:
 *   __collection  — ad pointed at /collections/<slug> (a model/category, not one product)
 *   __other       — dynamic / Advantage+ catalog / home — genuinely unattributed
 * so the report can render an honest "not product-specific" banner and reconcile
 * to the brand's total Meta spend.
 *
 * Additive, non-destructive — new table only, joined to commerce_daily_metrics /
 * the product catalog at read time.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ad_product_daily', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->date('date');
            $t->string('product_key', 191);        // product handle, or __collection / __other
            $t->decimal('spend', 14, 2)->default(0);
            $t->unsignedInteger('ads_count')->default(0);   // distinct ads that spent on this key that day
            $t->string('currency', 8)->nullable();
            $t->decimal('fx_rate_to_usd', 14, 8)->nullable();
            $t->boolean('is_complete')->default(true);
            $t->timestampTz('pulled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'date', 'product_key'], 'ad_product_unique');
            $t->index(['brand_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_product_daily');
    }
};
