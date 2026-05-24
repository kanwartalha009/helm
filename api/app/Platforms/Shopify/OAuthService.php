<?php

declare(strict_types=1);

namespace App\Platforms\Shopify;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Services\PlatformCredentialService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shopify per-brand OAuth flow. The agency has one unlisted custom app.
 * Each brand goes through OAuth once, the access_token is written into
 * PlatformConnection.credentials (encrypted).
 */
final class OAuthService
{
    /** TTL for the state token stored between authUrl() and handleCallback(). */
    private const STATE_TTL_SECS = 900; // 15 min

    private readonly Client $http;

    public function __construct(
        private readonly PlatformCredentialService $credentials,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'timeout'         => 20,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Build the Shopify install URL for one specific shop.
     *
     * The PlatformAdapter contract takes only a Brand, so the controller
     * stashes the shop domain in the request and passes it in via this method
     * (see ShopifyAdapter::authUrlForShop / ConnectionController::authUrl).
     */
    public function authUrl(Brand $brand, string $shopDomain): string
    {
        $shop  = $this->normaliseShop($shopDomain);
        $key   = $this->clientIdFor($brand);
        $scope = $this->scopesFor($brand);

        // Generate a single-use state token and store the brand_id behind it.
        // The callback verifies state by reading this cache entry.
        $state = Str::random(40);
        Cache::put($this->stateKey($state), [
            'brand_id' => $brand->id,
            'shop'     => $shop,
        ], self::STATE_TTL_SECS);

        $redirect = rtrim((string) config('app.url'), '/') . '/connections/shopify/callback';

        $params = [
            'client_id'    => $key,
            'scope'        => $scope,
            'redirect_uri' => $redirect,
            'state'        => $state,
        ];

        return "https://{$shop}/admin/oauth/authorize?" . http_build_query($params);
    }

    /**
     * Use the stored refresh_token to mint a new access_token. Shopify offline
     * tokens are now expiring (24h by default) and must be refreshed; the
     * grant_type for the token endpoint is the standard OAuth refresh flow.
     *
     * Returns the new credentials array shape the connection should be saved
     * with. Throws if refresh fails (caller decides whether to mark the
     * connection errored or trigger a re-install).
     *
     * @return array{access_token: string, refresh_token: string, expires_at: string, expires_in: int}
     */
    public function refreshAccessToken(PlatformConnection $conn): array
    {
        $refresh = (string) ($conn->credentials['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new RuntimeException(
                'No refresh_token stored on this connection. Disconnect and re-install Shopify to get an expiring token.'
            );
        }

        $brand = $conn->brand ?? throw new RuntimeException('Connection has no brand — orphaned row?');
        $shop  = $this->normaliseShop((string) $conn->external_id);
        $key    = $this->clientIdFor($brand);
        $secret = $this->clientSecretFor($brand);

        try {
            $response = $this->http->post("https://{$shop}/admin/oauth/access_token", [
                'json' => [
                    'client_id'     => $key,
                    'client_secret' => $secret,
                    'refresh_token' => $refresh,
                    'grant_type'    => 'refresh_token',
                ],
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Shopify token refresh failed: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body) || empty($body['access_token'])) {
            $hint = is_array($body) && isset($body['error']) ? (string) $body['error'] : 'unknown error';
            throw new RuntimeException("Shopify token refresh returned no access_token ({$hint}).");
        }

        return $this->packTokenResponse($body);
    }

    /**
     * Resolve a state token (set during authUrl()) to its stored brand_id.
     * Returns null when the token is unknown or expired.
     */
    public function resolveState(string $state): ?int
    {
        $entry = Cache::get($this->stateKey($state));
        if (! is_array($entry) || ! isset($entry['brand_id'])) {
            return null;
        }
        return (int) $entry['brand_id'];
    }

    /**
     * Read the Client ID for the brand's Partner app. Brand-specific creds
     * take precedence; the workspace-wide platform_credentials value is a
     * fallback for legacy brands that haven't been migrated yet.
     */
    private function clientIdFor(Brand $brand): string
    {
        $app = $brand->shopify_app ?? null;
        if (is_array($app) && ! empty($app['client_id'])) {
            return (string) $app['client_id'];
        }
        return $this->credentials->get('shopify', 'partner_app_key');
    }

    private function clientSecretFor(Brand $brand): string
    {
        $app = $brand->shopify_app ?? null;
        if (is_array($app) && ! empty($app['client_secret'])) {
            return (string) $app['client_secret'];
        }
        return $this->credentials->get('shopify', 'partner_app_secret');
    }

    /**
     * OAuth scopes for the install URL. Brands can override, but the canonical
     * Helm set lives on PlatformCredentialService::DEFAULTS so a fresh brand
     * doesn't have to type them.
     */
    private function scopesFor(Brand $brand): string
    {
        $app = $brand->shopify_app ?? null;
        if (is_array($app) && ! empty($app['scopes'])) {
            return (string) $app['scopes'];
        }
        return $this->credentials->get('shopify', 'partner_app_scopes');
    }

    /** @param array<string, mixed> $payload */
    public function handleCallback(Brand $brand, array $payload): PlatformConnection
    {
        $shop  = $this->normaliseShop((string) ($payload['shop'] ?? ''));
        $code  = (string) ($payload['code'] ?? '');
        $state = (string) ($payload['state'] ?? '');

        if ($shop === '' || $code === '' || $state === '') {
            throw new RuntimeException('Missing required OAuth callback parameters.');
        }

        if (! $this->verifyHmac($brand, $payload)) {
            throw new RuntimeException('Shopify callback HMAC verification failed.');
        }

        $stateEntry = Cache::pull($this->stateKey($state));
        if (! is_array($stateEntry) || (int) ($stateEntry['brand_id'] ?? 0) !== $brand->id) {
            throw new RuntimeException('OAuth state mismatch or expired.');
        }

        $tokens = $this->exchangeCodeForToken($brand, $shop, $code);
        $scopes = $this->scopesFor($brand);

        // updateOrCreate keyed on (brand, platform, external_id=shop) — if a
        // brand re-installs onto the same shop we overwrite the token in place.
        return PlatformConnection::updateOrCreate(
            [
                'brand_id'    => $brand->id,
                'platform'    => 'shopify',
                'external_id' => $shop,
            ],
            [
                'display_name' => $shop,
                'credentials'  => $tokens,
                'metadata'     => [
                    'scopes'       => $scopes,
                    'installed_at' => now()->toIso8601String(),
                ],
                'status'       => 'active',
                'last_error'   => null,
            ],
        );
    }

    /**
     * HMAC verification per Shopify docs: sort all params except `hmac` and
     * `signature` alphabetically, percent-encode as a query string, then
     * HMAC-SHA256 with the partner app secret. Compare in constant time.
     *
     * Takes the brand so we use the right per-brand Client Secret. Falls
     * back to the workspace value if the brand doesn't have its own.
     *
     * @param array<string, mixed> $payload
     */
    private function verifyHmac(Brand $brand, array $payload): bool
    {
        $provided = (string) ($payload['hmac'] ?? '');
        if ($provided === '') {
            return false;
        }

        $params = $payload;
        unset($params['hmac'], $params['signature']);

        ksort($params);

        // Shopify uses the standard application/x-www-form-urlencoded encoding.
        $message = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $secret   = $this->clientSecretFor($brand);
        $expected = hash_hmac('sha256', $message, $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Exchange the OAuth code for tokens. For Partner apps configured with
     * expiring offline tokens (the new default — non-expiring are no longer
     * accepted), Shopify returns `access_token`, `refresh_token`, and
     * `expires_in`. We store all three so a later sync can self-refresh.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: string, expires_in: int}
     */
    private function exchangeCodeForToken(Brand $brand, string $shop, string $code): array
    {
        $key    = $this->clientIdFor($brand);
        $secret = $this->clientSecretFor($brand);

        try {
            $response = $this->http->post("https://{$shop}/admin/oauth/access_token", [
                'json' => [
                    'client_id'     => $key,
                    'client_secret' => $secret,
                    'code'          => $code,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Shopify token exchange failed: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body) || empty($body['access_token'])) {
            $hint = is_array($body) && isset($body['error']) ? (string) $body['error'] : 'unknown error';
            throw new RuntimeException("Shopify token exchange returned no access_token ({$hint}).");
        }

        return $this->packTokenResponse($body);
    }

    /**
     * Normalise the token-endpoint response into the shape stored on the
     * connection. Accepts BOTH expiring (refresh_token + expires_in present)
     * and non-expiring (older custom-distribution apps) token responses.
     *
     * Why we accept both:
     *   - Some Partner Dashboard custom-distribution apps still hand back
     *     non-expiring offline tokens. The Admin API may reject them at
     *     sync time, but failing the install for that would block the
     *     operator from completing the OAuth handshake entirely. Better to
     *     save what Shopify gave us and surface the sync error later, when
     *     the actionable fix (toggle "Expiring offline access tokens" in
     *     the Partner Dashboard, re-install) is in the operator's hands.
     *
     * @param array<string, mixed> $body
     * @return array{access_token: string, refresh_token: string, expires_at: string, expires_in: int}
     */
    private function packTokenResponse(array $body): array
    {
        Log::info('Shopify token endpoint response keys', [
            'keys'       => array_keys($body),
            'expires_in' => $body['expires_in'] ?? null,
            'scope'      => $body['scope'] ?? null,
        ]);

        $accessToken  = (string) $body['access_token'];
        $refreshToken = (string) ($body['refresh_token'] ?? '');
        $expiresIn    = (int)    ($body['expires_in'] ?? 0);

        $expiresAt = $expiresIn > 0
            // 60-second skew margin on the local clock.
            ? now()->addSeconds($expiresIn - 60)->toIso8601String()
            : '';

        if ($expiresIn === 0) {
            Log::warning('Shopify returned a non-expiring offline token. Admin API may reject it on call; switch the Partner app to expiring offline tokens to refresh automatically.');
        }

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at'    => $expiresAt,
            'expires_in'    => $expiresIn,
        ];
    }

    /**
     * Accept `meller.myshopify.com`, `https://meller.myshopify.com`, or just
     * `meller`. Always returns `<handle>.myshopify.com`.
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
        // Whitelist: only allow *.myshopify.com to avoid SSRF-style abuse.
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i', $raw)) {
            throw new RuntimeException("Invalid Shopify shop domain: {$raw}");
        }
        return strtolower($raw);
    }

    private function stateKey(string $state): string
    {
        return "helm.shopify.state.{$state}";
    }
}
