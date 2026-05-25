<?php

declare(strict_types=1);

use App\Http\Controllers\OAuthCallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — OAuth callbacks only. Everything else is /api.
|--------------------------------------------------------------------------
*/

Route::get('/connections/shopify/install', [OAuthCallbackController::class, 'shopifyInstall'])
    ->name('connections.shopify.install');

Route::get('/connections/{platform}/callback', [OAuthCallbackController::class, 'callback'])
    ->name('connections.callback')
    ->where('platform', 'shopify|meta|google|tiktok');

Route::fallback(function () {
    $indexPath = public_path('app/index.html');
    if (! file_exists($indexPath)) {
        abort(404, 'Frontend build missing. Run `npm run build` and copy /web/dist to /api/public/app.');
    }
    return response()->file($indexPath);
});
