<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cost & contribution-margin configuration (master plan §4.2) — GO-1.2
|--------------------------------------------------------------------------
| Contribution margin = revenue − COGS − mapped ad spend.
|
| COGS precedence (CostResolver — single source of truth, never re-implement):
|   1. manual product cost   (product_costs, effective-dated)  → source 'manual'
|   2. Shopify unit cost     (product_catalog.unit_cost)       → source 'shopify'
|   3. brand gross_margin_pct (revenue × (1 − margin))         → source 'brand_margin'
|   4. nothing               → null, rendered "—" + a "set costs" hint. NEVER 0.
|
| Shipping and payment fees are NOT invented in v1 (§4.2: "NEVER invent shipping/fees
| v1 — those are config-ready fields defaulting null"). They are declared here so the
| formula has a home to grow into, and they are IGNORED while null. A margin that
| silently bakes in a guessed shipping cost is a wrong number, and wrong numbers are
| the one thing this product cannot ship.
*/

return [

    // Per-order shipping cost borne by the brand. null = not modelled (default).
    'shipping_per_order' => env('HELM_SHIPPING_PER_ORDER') !== null
        ? (float) env('HELM_SHIPPING_PER_ORDER')
        : null,

    // Payment-processing fee as a % of revenue. null = not modelled (default).
    'payment_fee_pct' => env('HELM_PAYMENT_FEE_PCT') !== null
        ? (float) env('HELM_PAYMENT_FEE_PCT')
        : null,

];
