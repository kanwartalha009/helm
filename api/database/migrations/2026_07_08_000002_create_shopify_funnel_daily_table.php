<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily web-funnel rows from Shopify's ShopifyQL `sessions` dataset, one per
 * (brand, day, dimension, segment): sessions → cart additions → reached checkout
 * → completed checkout, split by `session_country` (§10) or `landing_page_path`
 * (§11) of the monthly report. These metrics are additive across days, so they're
 * stored daily and summed to the month at read time — same shape as
 * meta_breakdown_daily, which is exactly why funnel data belongs in the daily
 * sync (unlike unique customer counts, which don't decompose to days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_funnel_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension', 32);          // 'country' | 'landing'
            $table->string('segment_key', 191);
            $table->string('segment_label', 191)->nullable();
            $table->unsignedBigInteger('sessions')->default(0);
            $table->unsignedBigInteger('cart_additions')->default(0);
            $table->unsignedBigInteger('reached_checkout')->default(0);
            $table->unsignedBigInteger('completed_checkout')->default(0);
            $table->boolean('is_complete')->default(true);
            $table->timestamp('pulled_at')->nullable();

            $table->unique(['brand_id', 'date', 'dimension', 'segment_key'], 'shopify_funnel_unique');
            $table->index(['brand_id', 'dimension', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_funnel_daily');
    }
};
