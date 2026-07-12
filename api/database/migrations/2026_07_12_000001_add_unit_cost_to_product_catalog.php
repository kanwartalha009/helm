<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-1.2 (master plan §4.2): per-product unit cost pulled from Shopify.
 *
 * VERIFIED 2026-07-12 against shopify.dev (Admin GraphQL): `InventoryItem.unitCost`
 * is a NULLABLE MoneyV2 reachable at ProductVariant.inventoryItem.unitCost, requiring
 * the read_inventory or read_products scope (both are in Helm's canonical scope set).
 * Shopify also gates it behind the "View product costs" permission once granular
 * product permissions are enabled — so the field can legitimately come back NULL.
 *
 * Missing ≠ zero: a product whose cost Shopify does not expose stores NULL here and
 * falls through the cost precedence chain (manual product_costs → brand
 * gross_margin_pct → null "—"). A 0 cost is NEVER inferred.
 *
 * unit_cost is the average of the product's non-null VARIANT costs (size variants
 * almost always share a cost; the average is documented and shown as such), stored in
 * `unit_cost_currency` — Shopify reports cost in the shop's currency, not converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_catalog', function (Blueprint $t): void {
            $t->decimal('unit_cost', 14, 2)->nullable()->after('total_inventory');
            $t->string('unit_cost_currency', 8)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('product_catalog', function (Blueprint $t): void {
            $t->dropColumn(['unit_cost', 'unit_cost_currency']);
        });
    }
};
