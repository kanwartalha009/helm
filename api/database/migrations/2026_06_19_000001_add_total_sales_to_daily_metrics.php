<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Shopify "Total sales" (ShopifyQL total_sales, Online Store) =
        // net sales + shipping + taxes + duties, returns netted. Stored next to
        // net_sales so the dashboard's "Total revenue" metric reads Shopify's
        // own figure instead of the order-reconstructed gross. Nullable: ad
        // platform rows leave it null, as does any day ShopifyQL didn't return.
        // Additive only — existing rows keep total_sales = null until re-synced.
        Schema::table('daily_metrics', function (Blueprint $t) {
            $t->decimal('total_sales', 14, 2)->nullable()->after('net_sales');
        });
    }

    public function down(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t) {
            $t->dropColumn('total_sales');
        });
    }
};
