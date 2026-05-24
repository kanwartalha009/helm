<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('daily_metrics', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('platform', 30);
            // date in BRAND's timezone, never UTC
            $t->date('date');

            // shopify fields (null for ad platforms)
            $t->decimal('revenue', 14, 2)->nullable();
            $t->decimal('revenue_net', 14, 2)->nullable();
            $t->integer('orders')->nullable();
            $t->decimal('refunds_amount', 14, 2)->nullable();
            $t->integer('refunded_orders')->nullable();

            // ad platform fields (null for shopify)
            $t->decimal('spend', 14, 2)->nullable();
            $t->bigInteger('impressions')->nullable();
            $t->integer('clicks')->nullable();
            $t->integer('conversions')->nullable();
            $t->decimal('conversion_value', 14, 2)->nullable();

            // currency — snapshotted at sync time
            $t->char('currency', 3);
            $t->decimal('fx_rate_to_usd', 14, 6);

            // meta
            $t->jsonb('metadata')->nullable();
            $t->boolean('is_complete')->default(false);
            $t->timestampTz('pulled_at');

            $t->unique(['brand_id', 'platform', 'date']);
            $t->index(['date', 'brand_id']);
            $t->index(['brand_id', 'date', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
