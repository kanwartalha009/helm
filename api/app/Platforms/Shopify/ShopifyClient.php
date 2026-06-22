<?php

declare(strict_types=1);

namespace App\Platforms\Shopify;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Owns every outbound HTTP call to Shopify GraphQL. Handles cost-based
 * throttling (extensions.cost.throttleStatus), exponential backoff, and
 * automatic re-auth on 401 via the optional onUnauthorized callback.
 *
 * Per-store access tokens live on PlatformConnection.credentials.access_token
 * (encrypted JSONB). Read directly from the connection passed by the adapter;
 * Shopify is NOT in PlatformCredentialService::ENV_MAP because it's per-brand.
 */
final class ShopifyClient
{
    /** Stable GraphQL Admin API version per docs/05-platforms/shopify.md. */
    private const API_VERSION = '2025-01';

    /** Minimum free cost budget. Below this we sleep and let credit refill. */
    private const COST_FLOOR = 200;

    /** Hard cap on a single throttle-sleep so we never block a worker forever. */
    private const MAX_SLEEP_SECS = 30;

    /** Retries for transient connection failures (DNS thread-spawn under load, connect timeout). */
    private const TRANSIENT_RETRIES = 3;

    private readonly Client $http;
    private string $accessToken;

    /**
     * Optional callback fired exactly once per request on a 401 response.
     * It MUST return a fresh access token string (or null if refresh is
     * impossible). If it returns a token we retry the request once; if it
     * returns null we throw the original 401 so the caller can record it.
     *
     * @var Closure(): ?string
     */
    private ?Closure $onUnauthorized = null;

    public function __construct(
        private readonly string $shopDomain,
        string $accessToken,
        ?Client $http = null,
    ) {
        $this->accessToken = $accessToken;
        $this->http = $http ?? new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Register a re-auth callback. The callback should refresh the underlying
     * connection's access token (e.g. via OAuthService::refreshAccessToken)
     * and return the new token string; ShopifyClient will retry the failing
     * call once with it. Return null to give up — we surface the 401.
     */
    public function onUnauthorized(Closure $cb): void
    {
        $this->onUnauthorized = $cb;
    }

    /**
     * Execute a GraphQL operation. Returns the `data` payload.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = [], ?string $apiVersion = null): array
    {
        try {
            return $this->doGraphql($query, $variables, allowRetry: true, apiVersion: $apiVersion);
        } catch (RuntimeException $e) {
            throw $e;
        }
    }

    /**
     * The inner call. `allowRetry` is set to false on the second attempt so
     * a token that still fails immediately surfaces — no infinite loops.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function doGraphql(string $query, array $variables, bool $allowRetry, ?string $apiVersion = null): array
    {
        $url = sprintf('https://%s/admin/api/%s/graphql.json', $this->shopDomain, $apiVersion ?? self::API_VERSION);

        $response = null;
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->post($url, [
                    'headers' => [
                        'X-Shopify-Access-Token' => $this->accessToken,
                        'Content-Type'           => 'application/json',
                        'Accept'                 => 'application/json',
                    ],
                    'json' => [
                        'query'     => $query,
                        'variables' => (object) $variables,
                    ],
                    'http_errors' => false,
                ]);
                break;
            } catch (ConnectException $e) {
                // Transient connection failure — a DNS getaddrinfo thread-spawn
                // failure under load, or a connect timeout. Back off and retry
                // before surfacing, so a momentary resource spike during a big
                // fan-out doesn't fail the whole sync.
                if ($attempt <= self::TRANSIENT_RETRIES) {
                    Log::warning('shopify.client.connect_retry', ['shop' => $this->shopDomain, 'attempt' => $attempt, 'message' => $e->getMessage()]);
                    sleep((int) min(8, 2 ** ($attempt - 1)));
                    continue;
                }
                throw new RuntimeException('Shopify request failed: ' . $e->getMessage(), 0, $e);
            } catch (GuzzleException $e) {
                throw new RuntimeException('Shopify request failed: ' . $e->getMessage(), 0, $e);
            }
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();
        $body   = json_decode($raw, true);

        // 401 → token is rejected. If we have a refresh callback and this is
        // the first attempt, try to mint a new token and retry once.
        if ($status === 401 && $allowRetry && $this->onUnauthorized !== null) {
            Log::info('shopify.client.401_retry', [
                'shop' => $this->shopDomain,
            ]);
            $newToken = ($this->onUnauthorized)();
            if (is_string($newToken) && $newToken !== '') {
                $this->accessToken = $newToken;
                return $this->doGraphql($query, $variables, allowRetry: false, apiVersion: $apiVersion);
            }
        }

        if (! is_array($body)) {
            throw new RuntimeException("Shopify returned non-JSON response (HTTP {$status}): " . substr($raw, 0, 200));
        }

        if ($status < 200 || $status >= 300) {
            $msg = $body['errors'] ?? $body['error_description'] ?? $body['error'] ?? "HTTP {$status}";
            throw new RuntimeException('Shopify error: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        if (! empty($body['errors'])) {
            throw new RuntimeException('Shopify GraphQL error: ' . json_encode($body['errors']));
        }

        // Cost-based throttling: if we just used most of our credit, sleep so the
        // next call doesn't get THROTTLED. Shopify's throttleStatus refills at
        // restoreRate cost units per second.
        $this->respectCostCeiling($body);

        return $body['data'] ?? [];
    }

    /**
     * Look at extensions.cost.throttleStatus and sleep if we're below the floor.
     *
     * @param array<string, mixed> $body
     */
    private function respectCostCeiling(array $body): void
    {
        $throttle = $body['extensions']['cost']['throttleStatus'] ?? null;
        if (! is_array($throttle)) {
            return;
        }

        $available   = (float) ($throttle['currentlyAvailable'] ?? self::COST_FLOOR);
        $restoreRate = (float) ($throttle['restoreRate'] ?? 50.0);

        if ($available >= self::COST_FLOOR || $restoreRate <= 0) {
            return;
        }

        $deficit = self::COST_FLOOR - $available;
        $seconds = (int) min(self::MAX_SLEEP_SECS, ceil($deficit / $restoreRate));
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
