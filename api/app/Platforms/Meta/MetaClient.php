<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Platforms\Support\Throttle;
use App\Services\PlatformCredentialService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Owns every outbound HTTP call to the Meta Marketing API. Reads the System
 * User token via PlatformCredentialService::get('meta', 'system_user_token')
 * on each request (so a rotated token takes effect without a redeploy).
 *
 * Handles Graph API cursor pagination and backs off on the documented
 * rate-limit error codes, honoring X-Business-Use-Case-Usage when Meta tells
 * us how long to wait. Mirrors the ShopifyClient pattern: http_errors are
 * disabled and every non-2xx / error payload is surfaced as a RuntimeException
 * so SyncBrandDayJob records it on the sync_logs row.
 */
final class MetaClient
{
    private const DEFAULT_VERSION = 'v24.0';
    private const BASE = 'https://graph.facebook.com';

    /** Meta rate-limit / transient error codes worth a backoff + retry. */
    private const RETRYABLE_CODES = [4, 17, 341, 613, 80000, 80003, 80004, 80014];

    private const MAX_RETRIES = 3;
    private const MAX_SLEEP_SECS = 30;

    private readonly Client $http;

    public function __construct(
        private readonly PlatformCredentialService $credentials,
        ?Client $http = null,
        private readonly ?string $version = null,
    ) {
        $this->http = $http ?? new Client([
            'timeout'         => 60,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * GET a Graph API node/edge. $path is relative (e.g. "act_123/insights"
     * or "me"); the version prefix and access token are added here.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed> decoded JSON body
     */
    public function get(string $path, array $query = []): array
    {
        $version = $this->version ?? (string) config('services.meta.version', self::DEFAULT_VERSION);
        $url     = self::BASE . '/' . trim($version, '/') . '/' . ltrim($path, '/');

        return $this->request($url, $query);
    }

    /**
     * Follow Graph API cursor pagination, accumulating every `data` row across
     * pages. Used for account discovery (me/adaccounts).
     *
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function paged(string $path, array $query = []): array
    {
        $out  = [];
        $body = $this->get($path, $query);

        while (true) {
            foreach (($body['data'] ?? []) as $row) {
                $out[] = $row;
            }

            $next = $body['paging']['next'] ?? null;
            if (! is_string($next) || $next === '') {
                break;
            }

            // `next` is a fully-formed URL incl. token + cursor — request as-is.
            $body = $this->request($next, []);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(string $url, array $query): array
    {
        $token = $this->credentials->get('meta', 'system_user_token');

        $attempt = 0;
        while (true) {
            $attempt++;

            try {
                $response = $this->http->get($url, [
                    'query'       => $query + ['access_token' => $token],
                    'http_errors' => false,
                ]);
            } catch (ConnectException $e) {
                // Transient connection failure (DNS thread-spawn under load,
                // connect timeout). Retry with backoff like a rate-limit, then
                // surface it.
                if ($attempt <= self::MAX_RETRIES) {
                    Log::warning('meta.client.connect_retry', ['attempt' => $attempt, 'message' => $e->getMessage()]);
                    Throttle::wait((int) min(self::MAX_SLEEP_SECS, 2 ** $attempt), 'meta', 'connect retry');
                    continue;
                }
                throw new RuntimeException('Meta request failed: ' . str_replace($token, '[redacted]', $e->getMessage()), 0, $e);
            } catch (GuzzleException $e) {
                throw new RuntimeException('Meta request failed: ' . str_replace($token, '[redacted]', $e->getMessage()), 0, $e);
            }

            $status = $response->getStatusCode();
            $raw    = (string) $response->getBody();
            $body   = json_decode($raw, true);

            if (! is_array($body)) {
                throw new RuntimeException("Meta returned non-JSON response (HTTP {$status}): " . substr($raw, 0, 200));
            }

            if (isset($body['error']) && is_array($body['error'])) {
                $code = (int) ($body['error']['code'] ?? 0);
                $msg  = (string) ($body['error']['message'] ?? 'Unknown Meta error');

                if (in_array($code, self::RETRYABLE_CODES, true) && $attempt <= self::MAX_RETRIES) {
                    $this->backoff($attempt, $response);
                    continue;
                }

                throw new RuntimeException("Meta API error {$code}: {$msg}");
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException("Meta API HTTP {$status}: " . substr($raw, 0, 200));
            }

            return $body;
        }
    }

    /** Exponential backoff, lifted to the header-reported wait when Meta provides one. */
    private function backoff(int $attempt, ResponseInterface $response): void
    {
        $headerSleep = $this->regainSecondsFromHeader($response);
        $expBackoff  = (int) (2 ** $attempt);
        $seconds     = (int) min(self::MAX_SLEEP_SECS, max(1, $headerSleep, $expBackoff));

        Log::info('meta.client.backoff', ['attempt' => $attempt, 'sleep_secs' => $seconds]);
        // Queue workers defer long waits back to the queue (Throttle defer
        // mode) so a Meta cool-down never pins a worker slot; console
        // commands keep the original inline sleep.
        Throttle::wait($seconds, 'meta', 'rate limit');
    }

    /** Parse X-Business-Use-Case-Usage for estimated_time_to_regain_access (minutes → secs). */
    private function regainSecondsFromHeader(ResponseInterface $response): int
    {
        $header = $response->getHeaderLine('X-Business-Use-Case-Usage');
        if ($header === '') {
            return 0;
        }

        $decoded = json_decode($header, true);
        if (! is_array($decoded)) {
            return 0;
        }

        $maxMinutes = 0;
        array_walk_recursive($decoded, function ($value, $key) use (&$maxMinutes): void {
            if ($key === 'estimated_time_to_regain_access' && is_numeric($value)) {
                $maxMinutes = max($maxMinutes, (int) $value);
            }
        });

        return (int) min(self::MAX_SLEEP_SECS, $maxMinutes * 60);
    }
}
