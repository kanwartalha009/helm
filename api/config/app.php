<?php

/*
|--------------------------------------------------------------------------
| Helm-specific overrides for the app config.
|--------------------------------------------------------------------------
|
| Laravel 11's minimal skeleton ships without config/app.php — all defaults
| come from the framework's vendor config. We need ONE custom key here:
|
|   'frontend_url' — the public URL where the React SPA is reachable.
|                    Used by OAuth callbacks (Shopify et al.), invite emails,
|                    and password-reset links. Without this, every controller
|                    that does `config('app.frontend_url')` falls back to
|                    'http://localhost:5173' and breaks in production.
|
| Falls back to APP_URL if FRONTEND_URL isn't set, because in this deploy
| the SPA and API live at the same hostname.
|
*/

return [
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
];
