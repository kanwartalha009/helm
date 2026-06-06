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
];
