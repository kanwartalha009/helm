<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The SPA at http://localhost:5173 calls the API at http://localhost:8000.
    | Without allowed_origins listing 5173, the browser blocks responses and
    | tokens never reach the SPA — which manifests as "I keep getting logged out".
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Pure bearer-token flow — no cookies, no credentials.
    'supports_credentials' => false,

];
