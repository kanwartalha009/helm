<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PlatformCredentialController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SyncStatusController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkspaceSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — every endpoint from docs/04-api/README.md.
|--------------------------------------------------------------------------
*/

// Public
Route::prefix('auth')->group(function (): void {
    Route::post('login',  [AuthController::class, 'login']);
    // MFA challenge after a successful password — public because the user is
    // not yet authenticated; the pending_token is the bearer of trust here.
    Route::post('mfa/verify', [AuthController::class, 'mfaVerify']);
    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,1');
    Route::post('password/reset',  [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1');
    Route::get('invitations/preview', [AuthController::class, 'previewInvitation']);
    Route::post('invitations/accept',  [AuthController::class, 'acceptInvitation'])
        ->name('auth.invitations.accept');
});

// Public, read-only shared report — gated by an unguessable token, not auth.
// This is how a client opens a report link Bosco sent them.
Route::get('r/{token}', [ReportController::class, 'publicShow'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {

    // Auth
    Route::prefix('auth')->group(function (): void {
        Route::post('logout',     [AuthController::class, 'logout']);
        Route::get('me',          [AuthController::class, 'me']);
        Route::patch('me',        [AuthController::class, 'updateMe']);
        Route::post('password',   [AuthController::class, 'changePassword']);
        Route::post('onboarding', [AuthController::class, 'completeOnboarding']);
        Route::post('avatar',     [AuthController::class, 'uploadAvatar']);
        Route::delete('avatar',   [AuthController::class, 'deleteAvatar']);
        Route::post('mfa/setup',   [AuthController::class, 'mfaSetup']);
        // Authenticated mfa/verify is the enrollment-confirmation variant —
        // takes { code } only. The unauthenticated copy (above, public) handles
        // the login-challenge variant with { code, pending_token }.
        Route::post('mfa/verify',  [AuthController::class, 'mfaVerify']);
        Route::post('mfa/disable', [AuthController::class, 'mfaDisable']);
    });

    // Workspace settings — General tab (master_admin only).
    Route::middleware('role:master_admin')->group(function (): void {
        Route::get('workspace-settings',   [WorkspaceSettingController::class, 'index']);
        Route::patch('workspace-settings', [WorkspaceSettingController::class, 'update']);
    });

    // Dashboard
    Route::get('dashboard',          [DashboardController::class, 'index']);
    Route::get('dashboard/summary',  [DashboardController::class, 'summary']);
    // Audience view — Meta spend split by a breakdown axis (audience/placement/…).
    Route::get('dashboard/audience', [DashboardController::class, 'audience']);

    // Reports — list available types. The brand-scoped build + share routes
    // live under the access.brand group below.
    Route::get('reports', [ReportController::class, 'index']);

    // Brands
    Route::get('brands',             [BrandController::class, 'index']);
    Route::post('brands',            [BrandController::class, 'store']);
    Route::middleware('access.brand')->group(function (): void {
        Route::get('brands/{brand}',     [BrandController::class, 'show']);
        Route::patch('brands/{brand}',   [BrandController::class, 'update']);
        Route::delete('brands/{brand}',  [BrandController::class, 'destroy']);

        Route::get('brands/{brand}/trend',   [DashboardController::class, 'trend']);
        Route::get('brands/{brand}/metrics', [BrandController::class, 'metrics']);

        // Reports — build a report for this brand, and snapshot it to a public
        // share token. Report type is validated against the registry.
        Route::get('brands/{brand}/reports/{type}',         [ReportController::class, 'show']);
        Route::post('brands/{brand}/reports/{type}/shares', [ReportController::class, 'createShare']);

        // Brand-level team assignment (brand_user_access). Admin/manager only —
        // gated by BrandPolicy::update inside the controller.
        Route::get('brands/{brand}/users',  [BrandController::class, 'users']);
        Route::put('brands/{brand}/users',  [BrandController::class, 'syncUsers']);

        // Platform connections (scoped to a brand)
        Route::get('brands/{brand}/connections',                          [ConnectionController::class, 'index']);
        // Lightweight install status — used by the SPA to poll after the OAuth
        // callback fires (sibling tab can't push to the parent, so we poll).
        Route::get('brands/{brand}/connections/shopify/status',           [ConnectionController::class, 'shopifyStatus']);
        // Live probe — calls Shopify directly and returns the 5 most recent
        // orders as raw data. Operator hits it from the brand page to prove
        // the install works end-to-end before the full sync runs.
        Route::post('brands/{brand}/connections/shopify/preview',         [ConnectionController::class, 'shopifyPreview'])
            ->middleware('throttle:10,1');
        // Manual paste-token connect — legacy path for stores with a working
        // shpat_ token from before the 2026 deprecation.
        Route::post('brands/{brand}/connections/shopify/token',           [ConnectionController::class, 'storeShopifyToken']);
        // OAuth flow kept available for ad platforms; Shopify path is hidden in the UI
        // but the route stays registered in case we re-enable OAuth in the future.
        Route::post('brands/{brand}/connections/{platform}/auth-url',     [ConnectionController::class, 'authUrl']);
        Route::get('brands/{brand}/connections/{platform}/available',     [ConnectionController::class, 'available']);
        Route::post('brands/{brand}/connections/{platform}/attach',       [ConnectionController::class, 'attach']);

        // Spec §04 puts this at 5/min, but the manual sync now fans out a
        // 7-day window so a single click dispatches 7 jobs/connection — the
        // request-side limit isn't the right defense any more. 30/min keeps
        // the dev cycle smooth; per-job queue concurrency still protects the
        // platform. Revisit before prod.
        Route::post('brands/{brand}/sync',     [SyncStatusController::class, 'trigger'])
            ->middleware('throttle:30,1');
        Route::post('brands/{brand}/backfill', [SyncStatusController::class, 'backfill']);
    });

    // Disconnect — not under a {brand} param because the connection id is unique.
    Route::delete('connections/{connection}', [ConnectionController::class, 'destroy']);

    // Sync status
    Route::get('sync/status',            [SyncStatusController::class, 'index']);
    Route::get('sync/status/export.csv', [SyncStatusController::class, 'exportCsv']);
    Route::post('sync-logs/{log}/retry', [SyncStatusController::class, 'retryLog'])
        ->middleware('throttle:30,1');

    // Master Sync now — dispatches the same fan-out as the per-brand Sync
    // now button, but for every active brand at once. Restricted to roles
    // that can `update` a brand (mirrors BrandPolicy). Throttle was 5,5
    // (5 hits per 5 minutes) which is too aggressive — testing/polling on
    // dashboards with 17+ brands triggered "Too many attempts" 429s after
    // legitimate clicks. Bumped to 12,5 (still rate-limited, but a real
    // operator clicking a few times in a row won't get blocked).
    Route::middleware(['role:master_admin,manager', 'throttle:12,5'])
        ->post('sync/all', [SyncStatusController::class, 'triggerAll']);

    // Users & invitations (Phase 1.5 — admin/manager only via policies)
    Route::middleware('role:master_admin,manager')->group(function (): void {
        Route::get('users',                 [UserController::class, 'index']);
        Route::get('users/{user}',          [UserController::class, 'show']);
        Route::patch('users/{user}',        [UserController::class, 'update']);
        Route::delete('users/{user}',       [UserController::class, 'destroy']);
        Route::get('invitations',           [UserController::class, 'listInvitations']);
        Route::post('invitations',          [UserController::class, 'invite']);
        Route::delete('invitations/{id}',   [UserController::class, 'revokeInvitation']);

        // Audit log — append-only read endpoint for the Audit log page.
        Route::get('audit-logs',             [AuditLogController::class, 'index']);
        Route::get('audit-logs/export.csv',  [AuditLogController::class, 'export']);
    });

    /*
    |----------------------------------------------------------------------
    | Platform credentials — master_admin only.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:master_admin')
        ->prefix('platform-credentials')
        ->group(function (): void {
            Route::get('/',         [PlatformCredentialController::class, 'index']);
            Route::get('schema',    [PlatformCredentialController::class, 'schema']);
            Route::post('/',        [PlatformCredentialController::class, 'store']);
            Route::post('{credential}/reveal', [PlatformCredentialController::class, 'reveal']);
            Route::post('{platform}/test',     [PlatformCredentialController::class, 'test'])
                ->where('platform', 'shopify|meta|google|tiktok');
            Route::delete('{credential}', [PlatformCredentialController::class, 'destroy']);
        });
});
