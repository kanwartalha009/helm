<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Platforms\Support\Throttle;
use App\Services\PlatformCredentialService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Owns every outbound call to the TikTok Marketing API (v1.3, REST). Reads the
 * Business Center owner token via PlatformCredentialService::get('tiktok',
 * 'bc_token') — DB-backed, so it survives config:cache.
 *
 * TikTok wraps every response as {code, message, request_id, data}; code 0 is
 * success. Rate-limit error 40100 (10 QPS/advertiser) gets an exponential
 * backoff + retry. Any other non-zero code surfaces as a RuntimeException so the
 * sync job records it on the sync_logs row (mirrors ShopifyClient/MetaClient).
 */
final class TikTokClient
{
    private const BASE = 'https://business-api.tiktok.com/open_api/v1.3/';

    /** TikTok rate-limit error code — back off and retry. */
    private const RATE_LIMIT_CODE = 40100;

    private const MAX_RETRIES = 3;
    private const MAX_SLEEP_SECS = 30;
    // TikTok's Business Center endpoints (bc/get/, bc/advertiser/get/) cap
    // page_size at 50 — anything larger returns error 40002. 50 is safe for
    // every endpoint (report/* allow more, but paging just fans out further).
    private const PAGE_SIZE = 50;

    private readonly Client $http;

    public function __construct(
        private readonly PlatformCredentialService $credentials,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'timeout'         => 60,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * GET a TikTok endpoint and return the `data` payload. Array query params
     * (dimensions, metrics) must be JSON-encoded by the caller — TikTok expects
     * them as JSON strings in the query.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $token = $this->credentials->get('tiktok', 'bc_token');
        $url   = self::BASE . ltrim($path, '/');

        $attempt = 0;
        while (true) {
            $attempt++;

            try {
                $response = $this->http->get($url, [
                    'headers' => [
                        'Access-Token' => $token,
                        'Content-Type' => 'application/json',
                    ],
                    'query'       => $query,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                throw new RuntimeException('TikTok request failed: ' . $e->getMessage(), 0, $e);
            }

            $raw  = (string) $response->getBody();
            $body = json_decode($raw, true);

            if (! is_array($body)) {
                throw new RuntimeException(
                    'TikTok returned non-JSON (HTTP ' . $response->getStatusCode() . '): ' . substr($raw, 0, 200)
                );
            }

            $code = (int) ($body['code'] ?? -1);
            if ($code === 0) {
                return is_array($body['data'] ?? null) ? $body['data'] : [];
            }

            if ($code === self::RATE_LIMIT_CODE && $attempt <= self::MAX_RETRIES) {
                // Long 40100 cool-downs release the queue job instead of
                // pinning a worker (Throttle defer mode); commands sleep inline.
                Throttle::wait((int) min(self::MAX_SLEEP_SECS, 2 ** $attempt), 'tiktok', 'rate limit 40100');
                continue;
            }

            throw new RuntimeException('TikTok API error ' . $code . ': ' . (string) ($body['message'] ?? 'unknown'));
        }
    }

    /**
     * Follow TikTok's page_info pagination, accumulating every data.list row.
     *
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function paged(string $path, array $query = []): array
    {
        $out  = [];
        $page = 1;

        do {
            $data = $this->get($path, $query + ['page' => $page, 'page_size' => self::PAGE_SIZE]);

            foreach (($data['list'] ?? []) as $row) {
                $out[] = $row;
            }

            $totalPage = (int) ($data['page_info']['total_page'] ?? 1);
            $page++;
        } while ($page <= $totalPage && $page < 200);

        return $out;
    }
}
