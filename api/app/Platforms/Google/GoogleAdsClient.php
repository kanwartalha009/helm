<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Services\PlatformCredentialService;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V24\GoogleAdsClient as Sdk;
use Google\Ads\GoogleAds\Lib\V24\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V24\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\V24\Services\SearchGoogleAdsRequest;
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
    public function __construct(
        private readonly PlatformCredentialService $credentials,
    ) {}

    /** Build a fresh SDK client from the DB-backed MCC credentials. */
    private function sdk(): Sdk
    {
        try {
            $oauth = (new OAuth2TokenBuilder())
                ->withClientId($this->credentials->get('google', 'client_id'))
                ->withClientSecret($this->credentials->get('google', 'client_secret'))
                ->withRefreshToken($this->credentials->get('google', 'refresh_token'))
                ->build();

            return (new GoogleAdsClientBuilder())
                ->withOAuth2Credential($oauth)
                ->withDeveloperToken($this->credentials->get('google', 'developer_token'))
                ->withLoginCustomerId((int) $this->digits($this->credentials->get('google', 'login_customer_id')))
                // REST transport: managed hosts (Cloudways) rarely have ext-grpc,
                // and the SDK defaults to gRPC. REST needs no extension, and our
                // calls use search() (not searchStream), which REST supports.
                ->withTransport('rest')
                ->build();
        } catch (Throwable $e) {
            throw new RuntimeException('Google Ads client build failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Run a GAQL query against one customer. Yields GoogleAdsRow objects.
     *
     * @return iterable<\Google\Ads\GoogleAds\V24\Services\GoogleAdsRow>
     */
    public function search(string $customerId, string $gaql): iterable
    {
        try {
            $service = $this->sdk()->getGoogleAdsServiceClient();

            $request = new SearchGoogleAdsRequest();
            $request->setCustomerId($this->digits($customerId));
            $request->setQuery($gaql);

            return $service->search($request)->iterateAllElements();
        } catch (Throwable $e) {
            throw new RuntimeException('Google Ads search failed: ' . $e->getMessage(), 0, $e);
        }
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
            throw new RuntimeException('Google Ads listAccessibleCustomers failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Strip everything but digits — customer IDs are often dashed (123-456-7890). */
    private function digits(string $id): string
    {
        return preg_replace('/\D/', '', $id) ?? '';
    }
}
