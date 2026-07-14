<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Services\PlatformCredentialService;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V24\GoogleAdsClient as Sdk;
use Google\Ads\GoogleAds\Lib\V24\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V24\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\V24\Services\SearchGoogleAdsRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Owns every outbound call to the Google Ads API via the official
 * google-ads-php SDK (V24). Credentials are DB-backed through
 * PlatformCredentialService (google.refresh_token / client_id / client_secret /
 * developer_token / login_customer_id) — NOT env, so they survive config:cache.
 * The MCC login-customer-id is set on every call; the SDK handles QuotaError
 * backoff internally.
 *
 * Mirrors the ShopifyClient/MetaClient convention: any failure is surfaced as a
 * RuntimeException so the sync job records it on the sync_logs row.
 */
final class GoogleAdsClient
{
    /**
     * ══ THE DEVELOPER-TOKEN QUOTA IS ONE SHARED BUDGET, NOT A PER-BRAND ONE ══
     *
     *     "rateScope": "DEVELOPER", "rateName": "Number of operations for basic access",
     *     "retryDelay": "5067s"
     *
     * A Basic-access developer token allows 15,000 operations PER DAY across EVERYTHING — every
     * brand, every customer id, every backfill, every manual "sync now". With 200+ brands it is
     * spent, and then Google locks the token out for the better part of two hours.
     *
     * The old client had no idea any of this existed. Once the token was exhausted, all 200+ brands
     * kept firing requests into a wall: each job waited on a network round-trip, failed, and the
     * next one did it again — for 84 minutes. That is how one quota error became "200+ brands
     * failed to sync".
     *
     * So the first RESOURCE_EXHAUSTED trips a breaker for exactly as long as Google asked us to wait.
     * Every call after that fails IMMEDIATELY and locally, with no request. Nothing is gained by
     * asking again before the retry window, and a fast honest failure beats a slow one.
     */
    private const BREAKER_KEY = 'google_ads:quota_exhausted_until';

    /** Cap the lockout so a malformed retryDelay can't disable Google Ads for a week. */
    private const MAX_BREAKER_SECONDS = 7200;

    /** Built once per instance, not once per query — see sdk(). */
    private ?Sdk $sdk = null;

    public function __construct(
        private readonly PlatformCredentialService $credentials,
    ) {}

    /**
     * Build the SDK client from the DB-backed MCC credentials.
     *
     * MEMOISED. This used to run on EVERY search() call — rebuilding the OAuth2 credential and the
     * whole client for each of the ~25 queries a single brand-day costs. Across 200 brands that is
     * thousands of needless client constructions (and token refreshes) per sync.
     */
    private function sdk(): Sdk
    {
        if ($this->sdk !== null) {
            return $this->sdk;
        }

        try {
            $oauth = (new OAuth2TokenBuilder())
                ->withClientId($this->credentials->get('google', 'client_id'))
                ->withClientSecret($this->credentials->get('google', 'client_secret'))
                ->withRefreshToken($this->credentials->get('google', 'refresh_token'))
                ->build();

            $this->sdk = (new GoogleAdsClientBuilder())
                ->withOAuth2Credential($oauth)
                ->withDeveloperToken($this->credentials->get('google', 'developer_token'))
                ->withLoginCustomerId((int) $this->digits($this->credentials->get('google', 'login_customer_id')))
                // REST transport: managed hosts (Cloudways) rarely have ext-grpc,
                // and the SDK defaults to gRPC. REST needs no extension, and our
                // calls use search() (not searchStream), which REST supports.
                ->withTransport('rest')
                ->build();

            return $this->sdk;
        } catch (Throwable $e) {
            throw new RuntimeException('Google Ads client build failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Run a GAQL query against one customer. Yields GoogleAdsRow objects.
     *
     * THROWS when the quota breaker is open. It does NOT return an empty iterable — an empty result
     * is indistinguishable from "this account spent nothing today", and that would write €0 spend
     * across 200 brands and call it a successful sync. Missing is not zero (rule 9): the exception
     * fails the day closed, the row keeps is_complete = false, and the UI shows an amber warning
     * instead of a confident, wrong number.
     *
     * @return iterable<\Google\Ads\GoogleAds\V24\Services\GoogleAdsRow>
     */
    public function search(string $customerId, string $gaql): iterable
    {
        $this->assertQuotaAvailable();

        try {
            $service = $this->sdk()->getGoogleAdsServiceClient();

            $request = new SearchGoogleAdsRequest();
            $request->setCustomerId($this->digits($customerId));
            $request->setQuery($gaql);

            return $service->search($request)->iterateAllElements();
        } catch (Throwable $e) {
            $this->tripBreakerIfQuotaExhausted($e);

            throw new RuntimeException('Google Ads search failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Fail fast, locally, while Google has us locked out. No network call, no waiting.
     */
    private function assertQuotaAvailable(): void
    {
        $until = Cache::get(self::BREAKER_KEY);
        if ($until === null) {
            return;
        }

        $seconds = max(0, (int) $until - time());
        if ($seconds <= 0) {
            Cache::forget(self::BREAKER_KEY);

            return;
        }

        throw new RuntimeException(sprintf(
            'Google Ads daily operation quota is exhausted (developer token, Basic access = 15,000 '
            . 'operations/day shared across ALL brands). Google asked us to wait; ~%d minute(s) left. '
            . 'No request was made. Apply for Standard access to remove this cap.',
            (int) ceil($seconds / 60),
        ));
    }

    /**
     * A RESOURCE_EXHAUSTED response carries the exact number of seconds Google wants us to wait.
     * Honour it rather than guessing — and honour it ONCE, globally, instead of letting every one of
     * 200 brands rediscover it the hard way.
     */
    private function tripBreakerIfQuotaExhausted(Throwable $e): void
    {
        $message = $e->getMessage();

        if (! str_contains($message, 'RESOURCE_EXHAUSTED') && ! str_contains($message, 'quotaError')) {
            return;
        }

        // "Too many requests. Retry in 5067 seconds." / "retryDelay": "5067s"
        $seconds = 0;
        if (preg_match('/Retry in (\d+) seconds/i', $message, $m) === 1) {
            $seconds = (int) $m[1];
        } elseif (preg_match('/"retryDelay":\s*"(\d+)s"/', $message, $m) === 1) {
            $seconds = (int) $m[1];
        }

        // No parseable delay → still trip, briefly. Hammering an exhausted token is never right.
        $seconds = min(max($seconds, 300), self::MAX_BREAKER_SECONDS);

        Cache::put(self::BREAKER_KEY, time() + $seconds, $seconds + 60);

        Log::error('google_ads.quota_exhausted', [
            'retry_in_seconds' => $seconds,
            'scope'            => 'DEVELOPER (shared across every brand)',
            'action'           => 'breaker tripped — all Google Ads calls will fail fast until it clears',
            'fix'              => 'apply for Standard access; Basic is capped at 15,000 operations/day',
        ]);
    }

    /** Seconds until Google Ads calls are allowed again. 0 = not blocked. */
    public static function quotaBlockedForSeconds(): int
    {
        $until = Cache::get(self::BREAKER_KEY);

        return $until === null ? 0 : max(0, (int) $until - time());
    }

    /** Clear the breaker by hand (e.g. after Standard access is granted). */
    public static function clearQuotaBreaker(): void
    {
        Cache::forget(self::BREAKER_KEY);
    }

    /**
     * Customer IDs (digits only) the OAuth user can access directly. Used as a
     * lightweight health check — child accounts under the MCC are enumerated via
     * a customer_client query (see GoogleAdsAdapter::listAvailableAccounts).
     *
     * @return array<int, string>
     */
    public function listAccessibleCustomers(): array
    {
        // Guarded too. This is the connection health check, so it is exactly what gets hammered
        // while everything is failing — the operator reloads the page, every brand's status widget
        // asks Google whether it is alive, and each of those spends quota we do not have.
        $this->assertQuotaAvailable();

        try {
            $service  = $this->sdk()->getCustomerServiceClient();
            $response = $service->listAccessibleCustomers(new ListAccessibleCustomersRequest());

            $ids = [];
            foreach ($response->getResourceNames() as $resourceName) {
                // 'customers/1234567890' → '1234567890'
                $ids[] = $this->digits((string) $resourceName);
            }

            return array_values(array_filter($ids));
        } catch (Throwable $e) {
            $this->tripBreakerIfQuotaExhausted($e);

            throw new RuntimeException('Google Ads listAccessibleCustomers failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Strip everything but digits — customer IDs are often dashed (123-456-7890). */
    private function digits(string $id): string
    {
        return preg_replace('/\D/', '', $id) ?? '';
    }
}
