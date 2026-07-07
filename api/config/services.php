<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Third-party services
    |--------------------------------------------------------------------------
    |
    | Meta (Facebook) Marketing API. One System User token — resolved via
    | PlatformCredentialService ('meta','system_user_token') with a
    | META_SYSTEM_USER_TOKEN env fallback — covers every ad account under the
    | agency Business Manager. See docs/05-platforms/meta.md.
    */
    'meta' => [
        // Marketing API floor: everything below v24.0 is deprecated 2026-06-09.
        // Override with META_API_VERSION to move to the latest (v25.0).
        'version'     => env('META_API_VERSION', 'v24.0'),
        'business_id' => env('META_BUSINESS_ID'),
    ],

    /*
    | TikTok Marketing API — the purchase + purchase-VALUE report metric names
    | (they vary by advertiser). Validate with `php artisan tiktok:diagnose`,
    | which probes candidate names and prints which are valid + their sums.
    |
    | value_metric_kind: 'per_purchase' when value_metric is an AVERAGE
    | (value_per_complete_payment — total revenue = value × purchases, the case
    | for Nude Project) or 'total' when it's already a total (e.g. a valid
    | total_complete_payment on accounts that expose one).
    */
    'tiktok' => [
        'purchase_metric'   => env('TIKTOK_PURCHASE_METRIC', 'complete_payment'),
        'value_metric'      => env('TIKTOK_VALUE_METRIC', 'value_per_complete_payment'),
        'value_metric_kind' => env('TIKTOK_VALUE_METRIC_KIND', 'per_purchase'),
        // creatives CtATC. Empty = OFF: Nude Project's pixel reports no add-to-cart
        // (total_add_to_cart / add_to_cart / on_web_add_to_cart all 40002-invalid
        // per tiktok:diagnose). Set TIKTOK_CART_METRIC once an account exposes one.
        'cart_metric'       => env('TIKTOK_CART_METRIC', ''),
    ],
];
