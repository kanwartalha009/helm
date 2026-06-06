<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t) {
            // Shopify "Net sales" = line items after discounts and returns,
            // excluding shipping, taxes, and duties (currentSubtotalPriceSet).
            // Additive + nullable: existing rows backfill on the next sync.
            $t->decimal('net_sales', 14, 2)->nullable()->after('revenue_net');
        });
    }

    public function down(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t) {
            $t->dropColumn('net_sales');
        });
    }
};
