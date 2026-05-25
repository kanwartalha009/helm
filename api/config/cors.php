<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| In production, the React SPA is served from the same hostname as the API
| (it's just /app/index.html under public/), so CORS is technically unused
| for normal requests. But the policy still needs to be sane for two cases:
|
|   1. Local development — Vite at :5173 calls the API at :8000.
|   2. Future split deployments — frontend on a CDN, API on a subdomain.
|
| The allow-list derives from APP_URL + FRONTEND_URL and always includes
| localhost so `npm run dev` keeps working.
|
*/

$origins = array_values(array_unique(array_filter([
    env('APP_URL'),
    env('FRONTEND_URL'),
    'http://localhost:5173',
    'http://127.0.0.1:5173',
])));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Pure bearer-token flow — no cookies, no credentials.
    'supports_credentials' => false,

];
