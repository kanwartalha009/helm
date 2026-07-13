<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdBoardsController;
use App\Http\Controllers\Api\AdsController;
use App\Http\Controllers\Api\AdsLibraryController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandAuditFindingsController;
use App\Http\Controllers\Api\BrandChatController;
use App\Http\Controllers\Api\BrandKlaviyoController;
use App\Http\Controllers\Api\BrandProductCostController;
use App\Http\Controllers\Api\AnomalyController;
use App\Http\Controllers\Api\BrandForecastController;
use App\Http\Controllers\Api\BrandGapMapController;
use App\Http\Controllers\Api\BrandTargetController;
use App\Http\Controllers\Api\BrandTruthController;
use App\Http\Controllers\Api\BudgetPlanController;
use App\Http\Controllers\Api\CampaignPlanController;
use App\Http\Controllers\Api\DataQualityController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\MarketCalendarController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\SeasonalStaleController;
use App\Http\Controllers\Api\BrandProductsController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BrandDataCoverageController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
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
    // Data-quality scores for every accessible brand — the dashboard chip merges these
    // client-side, deliberately keeping quality OUT of the dual-engine parity gate.
    Route::get('brands-quality',     [DataQualityController::class, 'index']);
    // Pacing per brand for the dashboard chip — a side endpoint, so targets stay out
    // of the dual-engine parity gate (same pattern as brands-quality).
    Route::get('brands-pacing',      [BrandTargetController::class, 'pacing']);
    // Open anomalies across accessible brands — the dashboard bell. Side endpoint, so
    // the two parity-gated dashboard engines stay untouched.
    Route::get('anomalies',          [AnomalyController::class, 'feed']);
    // Workspace-wide track record — Helm scored on its own advice across all visible brands.
    Route::get('track-record',        [RecommendationController::class, 'trackRecordAll']);
    // In-app weekly digest (GO-3.5). Works with or without Slack — Slack is optional delivery.
    Route::get('digest',              [DigestController::class, 'show']);
    // EU market calendar (GO-4.1) — legal sale periods, gift dates, commercial events.
    Route::get('market-calendar',     [MarketCalendarController::class, 'index']);
    // dashboard/summary + brands/{brand}/trend stubs deleted 2026-07-10 (D-020):
    // both returned hardcoded zeros / [] with no SPA consumer. Re-add with real
    // implementations when a feature needs them.
    // Audience view — Meta spend split by a breakdown axis (audience/placement/…).
    Route::get('dashboard/audience', [DashboardController::class, 'audience']);

    // Ads Library — internal winners (cross-brand; RBAC-scoped in the controller).
    Route::get('ads-library/winners', [AdsLibraryController::class, 'winners']);
    // Market library (Phase 3) — stored corpus reads (open to any authed user;
    // shared public data). Tracking + live search mutate → admin/manager only.
    Route::get('ads-library/market', [AdsLibraryController::class, 'market']);
    Route::get('ads-library/pages',  [AdsLibraryController::class, 'pages']);
    Route::get('ads-library/alerts', [AdsLibraryController::class, 'alerts']);
    Route::middleware('role:master_admin,manager')->group(function (): void {
        Route::post('ads-library/pages/resolve',  [AdsLibraryController::class, 'resolvePage']);
        Route::post('ads-library/pages',          [AdsLibraryController::class, 'trackPage']);
        Route::delete('ads-library/pages/{page}', [AdsLibraryController::class, 'untrackPage']);
        Route::post('ads-library/market/search',  [AdsLibraryController::class, 'liveSearch']);
    });
    // Boards + briefs (Phase 4) — the plan-ads workflow (any authed user).
    Route::get('ads-library/boards',    [AdBoardsController::class, 'index']);
    Route::post('ads-library/boards',   [AdBoardsController::class, 'store']);
    Route::get('ads-library/boards/{board}',  [AdBoardsController::class, 'show']);
    Route::post('ads-library/boards/{board}/items', [AdBoardsController::class, 'addItem']);
    // Optional LLM tag suggestion (D-016) — text-only, taxonomy-constrained,
    // operator-confirmed. Returns enabled:false when no LLM key is on file.
    Route::post('ads-library/boards/{board}/items/{item}/suggest-tags', [AdBoardsController::class, 'suggestTags']);
    Route::patch('ads-library/boards/{board}/items/{item}',  [AdBoardsController::class, 'updateItem']);
    Route::delete('ads-library/boards/{board}/items/{item}', [AdBoardsController::class, 'removeItem']);
    Route::post('ads-library/boards/{board}/brief', [AdBoardsController::class, 'createBrief']);
    Route::get('ads-library/briefs/{brief}',   [AdBoardsController::class, 'showBrief']);
    Route::patch('ads-library/briefs/{brief}', [AdBoardsController::class, 'updateBrief']);

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

        Route::get('brands/{brand}/metrics', [BrandController::class, 'metrics']);
        // Deep-analytics pages (slice 2.1/2.4 data): product performance +
        // rules-only store audit findings.
        Route::get('brands/{brand}/products',       [BrandProductsController::class, 'index']);
        // Product costs (GO-1.2) — read is brand-visible; writing a cost drives every
        // margin a client sees, so mutation is admin/manager only.
        Route::get('brands/{brand}/product-costs', [BrandProductCostController::class, 'index']);
        Route::middleware('role:master_admin,manager')->group(function (): void {
            Route::put('brands/{brand}/product-costs',            [BrandProductCostController::class, 'store']);
            Route::delete('brands/{brand}/product-costs/{cost}',  [BrandProductCostController::class, 'destroy']);
        });
        Route::get('brands/{brand}/audit-findings', [BrandAuditFindingsController::class, 'index']);
        // Onboarding data coverage + manual 12-month backfill (2026-07-10).
        // Trigger is admin/manager-only — backfills hammer platform APIs.
        Route::get('brands/{brand}/data-coverage', [BrandDataCoverageController::class, 'index']);
        // Data-quality score (GO-1.3) — full component breakdown, computed fresh so it
        // moves the moment a backfill lands. Gates GO-3/GO-4 recommendations.
        Route::get('brands/{brand}/data-quality', [DataQualityController::class, 'show']);
        // Triangulated truth (GO-1.4) — MER spine + bias-annotated platform ROAS.
        Route::get('brands/{brand}/truth', [BrandTruthController::class, 'show']);
        // Monthly targets + pacing (GO-2.1). Reading is brand-visible; setting a target
        // shapes what the operator is told about their own month → admin/manager only.
        Route::get('brands/{brand}/targets', [BrandTargetController::class, 'show']);
        Route::middleware('role:master_admin,manager')->group(function (): void {
            Route::put('brands/{brand}/targets',            [BrandTargetController::class, 'store']);
            Route::delete('brands/{brand}/targets/{month}', [BrandTargetController::class, 'destroy']);
        });

        // Forecast baseline (GO-2.3) — seasonal-naive + drift. REFUSES on thin history
        // rather than extrapolating; every number carries the `Modeled` label.
        Route::get('brands/{brand}/forecast', [BrandForecastController::class, 'show']);

        // Anomaly feed (GO-2.4). Dismissal REQUIRES a reason (validated server-side) —
        // it is the honesty record the GO-3 ledger will score against.
        Route::get('brands/{brand}/anomalies', [AnomalyController::class, 'index']);
        Route::post('brands/{brand}/anomalies/{anomaly}/dismiss', [AnomalyController::class, 'dismiss']);

        // Stop/Scale/Fix board (GO-3.2) — the ledger becomes operable. ACCEPT RECORDS
        // INTENT AND EXECUTES NOTHING: there is no path from here to an ad platform.
        // Deciding on advice for a client's account is a decision → admin/manager only.
        Route::get('brands/{brand}/recommendations', [RecommendationController::class, 'index']);
        // Helm's own track record (GO-3.3) — computed live from the ledger, never cached.
        Route::get('brands/{brand}/track-record',    [RecommendationController::class, 'trackRecord']);
        // Competitor gap map (GO-3.4) — Proxy competitor presence vs Verified own spend,
        // by market. The two sides are labelled separately and never mixed.
        Route::get('brands/{brand}/gap-map',         [BrandGapMapController::class, 'show']);

        // Seasonal campaign plans (GO-4.3). Generation is rule-assembled and free;
        // narration costs tokens, so it is a separate operator-triggered action (D-016).
        Route::get('brands/{brand}/plan-moments',    [CampaignPlanController::class, 'moments']);
        Route::get('brands/{brand}/plans',           [CampaignPlanController::class, 'index']);
        Route::get('brands/{brand}/plans/{plan}',    [CampaignPlanController::class, 'show']);
        Route::middleware('role:master_admin,manager')->group(function (): void {
            Route::post('brands/{brand}/plans',                  [CampaignPlanController::class, 'store']);
            Route::patch('brands/{brand}/plans/{plan}',          [CampaignPlanController::class, 'update']);
            Route::post('brands/{brand}/plans/{plan}/narrate',   [CampaignPlanController::class, 'narrate']);
        });
        Route::middleware('role:master_admin,manager')->group(function (): void {
            Route::post('brands/{brand}/recommendations/{recommendation}/accept',  [RecommendationController::class, 'accept']);
            Route::post('brands/{brand}/recommendations/{recommendation}/dismiss', [RecommendationController::class, 'dismiss']);
        });

        // Budget planner (GO-2.2) — a PLAN DOCUMENT. There is deliberately no code path
        // from here to any ad platform: Helm plans, humans execute (doctrine §2).
        Route::get('brands/{brand}/budget-plan', [BudgetPlanController::class, 'show']);
        Route::middleware('role:master_admin,manager')->group(function (): void {
            Route::put('brands/{brand}/budget-plan',         [BudgetPlanController::class, 'store']);
            Route::delete('brands/{brand}/budget-plan/{plan}', [BudgetPlanController::class, 'destroy']);
        });
        Route::middleware('role:master_admin,manager')
            ->post('brands/{brand}/backfill-dataset', [BrandDataCoverageController::class, 'store']);

        // Inventory Intelligence — per-product stock × ad spend × sessions for one brand.
        Route::get('brands/{brand}/inventory', [InventoryController::class, 'show']);
        // Manual "Sync now" for this page: refreshes stock, sales, product ad spend and sessions
        // over a short recent window. Queued — the UI polls the GET while it runs.
        Route::post('brands/{brand}/inventory/sync', [InventoryController::class, 'sync'])
            ->middleware('throttle:6,1');
        Route::get('brands/{brand}/inventory/sync',  [InventoryController::class, 'syncStatus']);

        // Ads hub — per-brand ad-platform Overview (Meta today; platform-agnostic).
        Route::get('brands/{brand}/ads', [AdsController::class, 'show']);
        Route::get('brands/{brand}/ads/campaigns/{campaign}', [AdsController::class, 'campaign']);
        Route::get('brands/{brand}/ads/campaigns/{campaign}/adsets', [AdsController::class, 'adsets']);
        Route::get('brands/{brand}/ads/creatives',            [AdsController::class, 'creatives']);
        // Seasonal-stale creatives (GO-3.1) — live ads still spending on a dead hook.
        // Keyword+date rule; no model in the trigger path.
        Route::get('brands/{brand}/ads/seasonal-stale',       [SeasonalStaleController::class, 'index']);
        Route::get('brands/{brand}/ads/creatives/{ad}/video', [AdsController::class, 'creativeVideo']);

        // Reports — build a report for this brand, and snapshot it to a public
        // share token. Report type is validated against the registry.
        Route::get('brands/{brand}/reports/{type}',         [ReportController::class, 'show']);
        Route::post('brands/{brand}/reports/{type}/shares', [ReportController::class, 'createShare']);
        // LLM layer (D-016, ratified 2026-07-10). Generation/editing is
        // admin/manager-only — every generate call spends real tokens.
        Route::middleware('role:master_admin,manager')->group(function (): void {
            Route::post('brands/{brand}/reports/{type}/narrative',  [ReportController::class, 'generateNarrative']);
            Route::patch('brands/{brand}/reports/{type}/narrative', [ReportController::class, 'saveNarrative']);
            Route::post('brands/{brand}/ask',                       [BrandChatController::class, 'ask']);

            // Per-brand Klaviyo private key (GO-1.1) — email-attributed revenue.
            // Sensitive credential → admin/manager only. Stored brand-scoped in
            // platform_credentials (platform='klaviyo').
            Route::get('brands/{brand}/klaviyo',    [BrandKlaviyoController::class, 'show']);
            Route::put('brands/{brand}/klaviyo',    [BrandKlaviyoController::class, 'store']);
            Route::post('brands/{brand}/klaviyo/test', [BrandKlaviyoController::class, 'test']);
            Route::delete('brands/{brand}/klaviyo', [BrandKlaviyoController::class, 'destroy']);
        });

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
        // Permanent hard-delete — only for an already-disabled user (see controller).
        Route::delete('users/{user}/permanent', [UserController::class, 'forceDelete']);
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
                ->where('platform', 'shopify|meta|google|tiktok|meta_adlib|llm|slack');
            Route::delete('{credential}', [PlatformCredentialController::class, 'destroy']);
        });
});
