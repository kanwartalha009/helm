<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-1.2 (master plan §4.2): operator-entered product costs — the OVERRIDE/fallback
 * for when Shopify has no unitCost (or has a wrong one). Additive; production is live.
 *
 * EFFECTIVE-DATED on purpose: costs change (a supplier price rise in March must not
 * silently rewrite January's margin). A margin computed for a past window uses the
 * cost row that was in force THEN — `effective_from <= window date`, latest wins.
 * That is why this is a row-per-change table and not a column on product_catalog.
 *
 * product_key = the Shopify HANDLE (same key ad_product_daily uses), so costs join
 * cleanly to ad spend and commerce revenue. workspace_id is the D-022 tenant seam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_costs', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)

            $t->string('product_key', 191);              // Shopify handle
            $t->decimal('unit_cost', 14, 2);             // native; never negative (validated)
            $t->string('currency', 8);
            $t->date('effective_from');                  // the cost applies from this date on

            $t->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            // One cost per product per effective date; re-saving the same date updates it.
            $t->unique(['brand_id', 'product_key', 'effective_from'], 'product_costs_unique');
            $t->index(['brand_id', 'product_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costs');
    }
};
