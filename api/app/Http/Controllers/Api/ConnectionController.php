<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformConnectionResource;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Meta\MetaAdapter;
use App\Platforms\PlatformRegistry;
use App\Platforms\Shopify\ShopifyAdapter;
use App\Platforms\Shopify\ShopifyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class ConnectionController extends Controller
{
    public function __construct(private readonly PlatformRegistry $registry) {}

    /** GET /api/brands/{brand}/connections */
    public function index(Brand $brand): AnonymousResourceCollection
    {
        $this->authorize('view', $brand);
        return PlatformConnectionResource::collection($brand->connections()->get());
    }

    /**
     * POST /api/brands/{brand}/connections/{platform}/auth-url
     *
     * For Shopify, also accepts the brand's Partner app Client ID + Secret —
     * persists them on `brands.shopify_app` (encrypted) so subsequent OAuth
     * handshakes for this brand use the same credentials without retyping.
     * If already stored, the fields can be omitted from the request.
     */
    public function authUrl(Request $request, Brand $brand, string $platform): JsonResponse
    {
        $this->authorize('update', $brand);

        $adapter = $this->registry->for($platform);

        if ($adapter instanceof ShopifyAdapter) {
            $data = $request->validate([
                'shop_domain'   => ['required', 'string', 'max:190'],
                'client_id'     => ['nullable', 'string', 'max:120'],
                'client_secret' => ['nullable', 'string', 'max:120'],
                'scopes'        => ['nullable', 'string', 'max:500'],
            ]);

            // If new app credentials arrived, persist them on the brand. We
            // merge so partial updates don't wipe the other half.
            if (! empty($data['client_id']) || ! empty($data['client_secret']) || ! empty($data['scopes'])) {
                $existing = $brand->shopify_app ?? [];
                $brand->shopify_app = array_merge(is_array($existing) ? $existing : [], array_filter([
                    'client_id'     => $data['client_id']     ?? null,
                    'client_secret' => $data['client_secret'] ?? null,
                    'scopes'        => $data['scopes']        ?? null,
                ]));
                $brand->save();
            }

            // Require credentials to exist before generating the install URL —
            // either on the brand or as a workspace fallback. We'd otherwise
            // generate a malformed URL.
            $app = $brand->shopify_app ?? [];
            $hasClientId = ! empty($app['client_id'])
                || $this->workspaceHas('shopify', 'partner_app_key');
            if (! $hasClientId) {
                return response()->json([
                    'message' => 'Configure this brand\'s Shopify Partner app first (Client ID + Secret).',
                ], 422);
            }

            return response()->json([
                'url' => $adapter->authUrlForShop($brand, $data['shop_domain']),
            ]);
        }

        return response()->json([
            'url' => $adapter->authUrl($brand),
        ]);
    }

    /**
     * Workspace platform_credentials presence check — short helper so we
     * don't have to depend on PlatformCredentialService in the controller.
     */
    private function workspaceHas(string $platform, string $key): bool
    {
        return app(\App\Services\PlatformCredentialService::class)->has($platform, $key);
    }

    /**
     * GET /api/brands/{brand}/connections/{platform}/available
     *
     * Lists attachable accounts for a platform. Only meaningful for ad
     * platforms (Meta/Google/TikTok) — Shopify is per-store via OAuth.
     * Returns 501 until the ad-platform adapters implement listAvailableAccounts.
     */
    public function available(Brand $brand, string $platform): JsonResponse
    {
        $this->authorize('update', $brand);

        try {
            $adapter = $this->registry->for($platform);

            // Account discovery draws from the org-level token (Business
            // Manager / MCC / BC), not a brand connection, so a connection need
            // not exist yet — use the brand's if present, else a transient one
            // so the operator can pick accounts before the first attach.
            $conn = PlatformConnection::query()
                ->where('brand_id', $brand->id)
                ->where('platform', $platform)
                ->first()
                ?? new PlatformConnection(['brand_id' => $brand->id, 'platform' => $platform]);

            return response()->json([
                'accounts' => $adapter->listAvailableAccounts($conn),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => "Listing {$platform} accounts is not yet supported: " . $e->getMessage(),
            ], 501);
        }
    }

    /**
     * POST /api/brands/{brand}/connections/{platform}/attach
     *
     * Attaches one of the platform's accounts to this brand. Phase 2 — once
     * Meta/Google/TikTok adapters ship. Returns 501 until then so the UI
     * never gets a silent `{todo:'implement'}` 200.
     */
    public function attach(Request $request, Brand $brand, string $platform): JsonResponse
    {
        $this->authorize('update', $brand);

        // Accept a single external_id or a list of account_ids. Meta blends
        // every selected ad account into one brand connection (see MetaAdapter);
        // its daily metrics are summed at sync time.
        $data = $request->validate([
            'account_ids'   => ['sometimes', 'array', 'min:1'],
            'account_ids.*' => ['string', 'max:190'],
            'external_id'   => ['sometimes', 'string', 'max:190'],
        ]);

        $ids = $data['account_ids'] ?? (isset($data['external_id']) ? [$data['external_id']] : []);
        if ($ids === []) {
            return response()->json(['message' => 'Provide account_ids[] or external_id.'], 422);
        }

        // Any adapter exposing attachAccounts() supports the picker flow — Meta
        // and Google today, TikTok once built. Others (e.g. Shopify, which is
        // per-store OAuth) return 501 so the UI shows a clear "not available yet".
        $adapter = $this->registry->for($platform);
        if (! method_exists($adapter, 'attachAccounts')) {
            return response()->json([
                'message' => ucfirst($platform) . ' account attach is not available yet.',
            ], 501);
        }

        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $platform)
            ->first()
            ?? new PlatformConnection(['brand_id' => $brand->id, 'platform' => $platform]);

        try {
            $adapter->attachAccounts($conn, $ids);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not attach account(s): ' . $e->getMessage()], 422);
        }

        return (new PlatformConnectionResource($conn->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/brands/{brand}/connections/shopify/preview
     *
     * Live probe — pulls the 5 most recent orders directly from Shopify and
     * returns the raw payload. Used by the brand page to prove an installed
     * connection actually works against the merchant's Admin API before a
     * full sync is triggered. Errors are returned as 422 with Shopify's
     * verbatim message so the operator can see exactly what failed.
     */
    public function shopifyPreview(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->first();

        if (! $conn) {
            return response()->json([
                'message' => 'No Shopify connection on this brand. Install the app first.',
            ], 404);
        }

        // Run the adapter's refresh-if-needed before the call so an expired
        // token rotates transparently. For admin-custom-app tokens it's a no-op.
        try {
            $adapter = $this->registry->for('shopify');
            if ($adapter instanceof ShopifyAdapter) {
                $adapter->refreshIfNeeded($conn);
                $conn->refresh();
            }
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Token refresh failed: ' . $e->getMessage(),
            ], 422);
        }

        $accessToken = (string) ($conn->credentials['access_token'] ?? '');
        if ($accessToken === '') {
            return response()->json([
                'message' => 'Connection has no access token. Re-install Shopify.',
            ], 422);
        }

        $shop   = (string) $conn->external_id;
        $client = new ShopifyClient($shop, $accessToken);

        $query = <<<'GQL'
{
  shop { name myshopifyDomain currencyCode ianaTimezone }
  orders(first: 5, sortKey: CREATED_AT, reverse: true) {
    edges {
      node {
        id
        name
        createdAt
        currentTotalPriceSet { shopMoney { amount currencyCode } }
        customer { id email }
        lineItems(first: 3) {
          edges { node { title quantity } }
        }
      }
    }
  }
}
GQL;

        try {
            $data = $client->graphql($query);
        } catch (Throwable $e) {
            // Persist the error onto the connection so the UI stays consistent
            // with what just happened — same pattern as the always-save token
            // flow. The operator sees the row flip to errored.
            $conn->update([
                'status'     => 'errored',
                'last_error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Shopify rejected the call: ' . $e->getMessage(),
            ], 422);
        }

        // If we got here, the connection works — make sure the row reflects that.
        if ($conn->status !== 'active' || $conn->last_error !== null) {
            $conn->update(['status' => 'active', 'last_error' => null]);
        }

        $edges  = $data['orders']['edges'] ?? [];
        $orders = is_array($edges) ? array_map(fn ($e) => $e['node'] ?? [], $edges) : [];

        return response()->json([
            'shop'   => $data['shop'] ?? null,
            'orders' => $orders,
            'count'  => count($orders),
        ]);
    }

    /**
     * GET /api/brands/{brand}/connections/shopify/status
     *
     * Returns the Shopify-connection status for one brand. Used by the SPA
     * after redirecting from the OAuth callback to confirm the install
     * actually wrote a row, AND to drive the Connections tab indicator.
     *
     * Response shape is small on purpose — the brand detail endpoint already
     * returns the full connection objects; this is the lightweight poll.
     */
    public function shopifyStatus(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->first();

        if (! $conn) {
            return response()->json([
                'installed' => false,
                'status'    => null,
                'shop'      => null,
                'lastError' => null,
                'lastSyncAt' => null,
            ]);
        }

        return response()->json([
            'installed'  => true,
            'status'     => $conn->status,
            'shop'       => $conn->external_id,
            'lastError'  => $conn->last_error,
            'lastSyncAt' => $conn->last_sync_at?->toIso8601String(),
        ]);
    }

    /** DELETE /api/connections/{connection} */
    public function destroy(PlatformConnection $connection): JsonResponse
    {
        $brand = $connection->brand;
        $this->authorize('update', $brand);

        $connection->delete();
        return response()->json(null, 204);
    }

    /**
     * POST /api/brands/{brand}/connections/shopify/token
     *
     * Manual Shopify connect. The intern creates a custom app inside the
     * store's admin (Settings → Apps → Develop apps), grabs the Admin API
     * access token (shpat_...), and pastes it here. We validate the token
     * by hitting Shopify with a tiny shop query — if it returns 200 with a
     * matching `myshopifyDomain`, we persist the connection. This replaces
     * the OAuth install flow for new brands per docs/05-platforms/shopify-store-onboarding.md.
     *
     * Body: { shop_domain, access_token, api_key?, api_secret? }
     */
    public function storeShopifyToken(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $data = $request->validate([
            'shop_domain'  => ['required', 'string', 'max:190'],
            'access_token' => ['required', 'string', 'max:255'],
            'api_key'      => ['nullable', 'string', 'max:120'],
            'api_secret'   => ['nullable', 'string', 'max:120'],
        ]);

        $shop = $this->normaliseShop($data['shop_domain']);

        // Try to validate against Shopify, but DO NOT block persistence on the
        // result. Operator's input always reaches the database; the validation
        // outcome is recorded on the connection itself so they can see what
        // Shopify said and fix the token later.
        $validation = $this->validateShopifyToken($shop, $data['access_token']);
        $info       = $validation['info'];
        $error      = $validation['error'];

        // If Shopify confirmed a different shop, treat that as a fatal user
        // error before we save (we'd otherwise persist a token that points at
        // someone else's store). Everything else — token rejected, network
        // down, deprecated token, etc. — still saves with status=errored.
        if ($info && ! empty($info['myshopifyDomain'])) {
            $resolved = strtolower((string) $info['myshopifyDomain']);
            if ($resolved !== $shop) {
                return response()->json([
                    'message' => "Token belongs to {$resolved}, not {$shop}. Re-copy from the right store.",
                ], 422);
            }
        }

        // Pre-check the shop/another-brand conflict before hitting Postgres.
        // platform_connections has unique(platform, external_id) — different
        // brand trying to link the same Shopify shop would otherwise 500.
        $conflict = PlatformConnection::query()
            ->where('platform', 'shopify')
            ->where('external_id', $shop)
            ->where('brand_id', '!=', $brand->id)
            ->first();
        if ($conflict) {
            return response()->json([
                'message' => "Shop {$shop} is already connected to another brand. Disconnect it there first.",
            ], 409);
        }

        // Preserve the original installed_at on reinstall — audit history.
        $existing = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('external_id', $shop)
            ->first();
        $installedAt = $existing?->metadata['installed_at'] ?? now()->toIso8601String();

        $connection = PlatformConnection::updateOrCreate(
            [
                'brand_id'    => $brand->id,
                'platform'    => 'shopify',
                'external_id' => $shop,
            ],
            [
                'display_name' => (string) ($info['name'] ?? $shop),
                'credentials'  => [
                    'access_token'  => $data['access_token'],
                    'refresh_token' => '',
                    'expires_at'    => '',
                    'expires_in'    => 0,
                    'api_key'       => $data['api_key'] ?? null,
                    'api_secret'    => $data['api_secret'] ?? null,
                ],
                'metadata'     => [
                    'connection_type' => 'admin_custom_app',
                    'currency'        => (string) ($info['currencyCode'] ?? ''),
                    'timezone'        => (string) ($info['ianaTimezone'] ?? ''),
                    'shop_name'       => (string) ($info['name'] ?? ''),
                    'installed_at'    => $installedAt,
                    'validation_at'   => now()->toIso8601String(),
                ],
                'status'       => $error === null ? 'active' : 'errored',
                'last_error'   => $error,
            ],
        );

        // If Shopify gave us authoritative currency / timezone, sync them onto
        // the brand. Only happens on a successful validation.
        if ($info) {
            $brandPatch = [];
            if (! empty($info['currencyCode']) && $brand->base_currency !== $info['currencyCode']) {
                $brandPatch['base_currency'] = (string) $info['currencyCode'];
            }
            if (! empty($info['ianaTimezone']) && $brand->timezone !== $info['ianaTimezone']) {
                $brandPatch['timezone'] = (string) $info['ianaTimezone'];
            }
            if ($brandPatch) {
                $brand->update($brandPatch);
            }
        }

        return response()->json([
            'connection' => new PlatformConnectionResource($connection),
            'shop'       => $info ? [
                'name'     => $info['name'] ?? null,
                'domain'   => $info['myshopifyDomain'] ?? $shop,
                'currency' => $info['currencyCode'] ?? null,
                'timezone' => $info['ianaTimezone'] ?? null,
            ] : null,
            'validation' => [
                'ok'    => $error === null,
                'error' => $error,
            ],
        ], 201);
    }

    /**
     * Probe Shopify with the token. Returns { info: ?array, error: ?string }.
     * Never throws — both paths return the same shape so the caller can decide
     * what to do with the result. Logged for support visibility.
     *
     * @return array{info: ?array<string, mixed>, error: ?string}
     */
    private function validateShopifyToken(string $shop, string $token): array
    {
        try {
            $client = new ShopifyClient($shop, $token);
            $body   = $client->graphql('{ shop { name currencyCode ianaTimezone myshopifyDomain } }');
            $info   = $body['shop'] ?? null;

            if (! is_array($info) || empty($info['myshopifyDomain'])) {
                return [
                    'info'  => null,
                    'error' => 'Shopify accepted the request but returned no shop data. Token may be revoked.',
                ];
            }

            \Illuminate\Support\Facades\Log::info('Shopify token validated', [
                'shop' => $shop,
                'name' => $info['name'] ?? null,
            ]);

            return ['info' => $info, 'error' => null];
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Shopify token validation failed (saved anyway)', [
                'shop'  => $shop,
                'error' => $e->getMessage(),
            ]);
            return ['info' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Strip protocol, trailing slash, lowercase, and append .myshopify.com if
     * the operator pasted a short handle. Same rules as OAuthService — kept
     * here so the manual-token endpoint is self-contained.
     */
    private function normaliseShop(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('#^https?://#i', '', $raw) ?? $raw;
        $raw = rtrim($raw, '/');
        if ($raw === '') {
            return $raw;
        }
        if (! str_contains($raw, '.')) {
            $raw .= '.myshopify.com';
        }
        return strtolower($raw);
    }
}
