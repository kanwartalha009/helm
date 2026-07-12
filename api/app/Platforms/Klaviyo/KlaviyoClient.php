<?php

declare(strict_types=1);

namespace App\Platforms\Klaviyo;

use App\Platforms\Support\PlatformRateLimitedException;
use App\Services\PlatformCredentialService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Client for the Klaviyo API (the ONLY place Klaviyo HTTP is allowed — adapter
 * guardrail). The private key is PER BRAND (each brand is a separate Klaviyo
 * account) → every call takes a $brandId and reads platform_credentials
 * (platform='klaviyo', key='private_key', brand_id). The key is sent in the
 * Authorization header and is NEVER logged or returned outward.
 *
 * Rate limits (config klaviyo.rate): metric-aggregates burst 3/s, steady 60/m.
 * On 429 the client throws PlatformRateLimitedException with the Retry-After the
 * API reports, so the day job defers to the queue and a backfill sleeps inline —
 * identical posture to the other platform clients.
 */
class KlaviyoClient
{
    public function __construct(private readonly PlatformCredentialService $credentials) {}

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(int $brandId, string $path, array $query = []): array
    {
        return $this->send($brandId, 'get', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $body JSON:API document
     * @return array<string, mixed>
     */
    public function post(int $brandId, string $path, array $body): array
    {
        return $this->send($brandId, 'post', $path, ['json' => $body]);
    }

    /**
     * The Placed-Order (config klaviyo.conversion_metric) metric id for this brand,
     * resolved by name from GET /metrics/. Returns null when the metric is absent
     * (a brand with no orders metric — the fetcher then yields nothing, never a 0).
     */
    public function conversionMetricId(int $brandId): ?string
    {
        $want = strtolower((string) config('klaviyo.conversion_metric', 'Placed Order'));
        $body = $this->get($brandId, 'metrics/', ['fields[metric]' => 'name']);

        foreach ((array) ($body['data'] ?? []) as $m) {
            $name = strtolower((string) ($m['attributes']['name'] ?? ''));
            if ($name === $want) {
                return (string) ($m['id'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * Lightest possible live check for the credential card / diagnose: resolve the
     * conversion metric id with the saved per-brand key. Never leaks the key.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(int $brandId): array
    {
        if (! $this->credentials->has('klaviyo', 'private_key', $brandId)) {
            return ['ok' => false, 'message' => 'No Klaviyo key saved for this brand yet.'];
        }

        try {
            $id = $this->conversionMetricId($brandId);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $metric = (string) config('klaviyo.conversion_metric', 'Placed Order');

        return $id !== null
            ? ['ok' => true, 'message' => "Klaviyo key valid — found the “{$metric}” metric."]
            : ['ok' => false, 'message' => "Key works, but no “{$metric}” metric on this account. Place a test order or check the account."];
    }

    /**
     * @param array{query?: array<string,mixed>, json?: array<string,mixed>} $options
     * @return array<string, mixed>
     */
    private function send(int $brandId, string $method, string $path, array $options): array
    {
        $key  = $this->credentials->get('klaviyo', 'private_key', $brandId);
        $base = rtrim((string) config('klaviyo.base', 'https://a.klaviyo.com/api/'), '/') . '/';

        try {
            $req = Http::timeout(40)->withHeaders([
                'Authorization' => 'Klaviyo-API-Key ' . $key,
                'revision'      => (string) config('klaviyo.revision', '2026-04-15'),
                'accept'        => 'application/json',
                'content-type'  => 'application/json',
            ]);

            $res = $method === 'post'
                ? $req->post($base . $path, $options['json'] ?? [])
                : $req->get($base . $path, $options['query'] ?? []);
        } catch (Throwable $e) {
            throw new RuntimeException('Klaviyo request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($res->status() === 429) {
            // Retry-After is seconds (Klaviyo). Fall back to 60s if absent.
            $retry = (int) ($res->header('Retry-After') ?: 60);
            throw new PlatformRateLimitedException(max(1, $retry), 'klaviyo', 'Klaviyo rate limit');
        }

        if (! $res->successful()) {
            $body   = $res->json();
            $detail = is_array($body) ? (string) ($body['errors'][0]['detail'] ?? '') : '';
            throw new RuntimeException('Klaviyo API ' . $res->status() . ($detail !== '' ? ': ' . $detail : ''));
        }

        $body = $res->json();

        return is_array($body) ? $body : [];
    }
}
