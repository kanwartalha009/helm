<?php

declare(strict_types=1);

namespace App\Platforms\Shopify;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Shopify adapter. Per-brand auth (each brand has its own access_token
 * stored encrypted on PlatformConnection.credentials.access_token).
 *
 * Unlike Meta/Google/TikTok, Shopify does NOT use PlatformCredentialService
 * for the access token — every brand auths its own store via the agency's
 * custom unlisted app. The Partner-app key/secret/scopes ARE in
 * PlatformCredentialService since they're shared across every brand install.
 */
final class ShopifyAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly RevenueFetcher $revenue,
    ) {}

    public function key(): string
    {
        return 'shopify';
    }

    public function label(): string
    {
        return 'Shopify';
    }

    /**
     * PlatformAdapter contract takes only the Brand; Shopify also needs a shop
     * domain. Callers should prefer authUrlForShop() — this overload exists
     * only so the interface stays single-arg for ad platforms.
     */
    public function authUrl(Brand $brand): string
    {
        throw new RuntimeException('Shopify auth needs a shop domain — call authUrlForShop().');
    }

    /** Shopify-specific: caller supplies the shop the user wants to install on. */
    public function authUrlForShop(Brand $brand, string $shopDomain): string
    {
        return $this->oauth->authUrl($brand, $shopDomain);
    }

    /** Resolve the brand_id stashed under an OAuth state token. */
    public function resolveState(string $state): ?int
    {
        return $this->oauth->resolveState($state);
    }

    /** @param array<string, mixed> $payload */
    public function handleCallback(Brand $brand, array $payload): PlatformConnection
    {
        return $this->oauth->handleCallback($brand, $payload);
    }

    /** @return array<int, array{external_id: string, name: string, currency: string}> */
    public function listAvailableAccounts(PlatformConnection $conn): array
    {
        // Shopify is single-store per connection — listing isn't meaningful.
        // The shop attached on install is the only account.
        return [[
            'external_id' => $conn->external_id,
            'name'        => $conn->display_name ?? $conn->external_id,
            'currency'    => (string) ($conn->metadata['currency'] ?? ''),
        ]];
    }

    public function attachAccount(PlatformConnection $conn, string $externalId): void
    {
        // Shopify connections are created during the OAuth callback with the
        // shop domain already set. There is no separate "pick an account" step.
        throw new RuntimeException('Shopify connections do not support attachAccount; created during OAuth callback.');
    }

    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        $this->refreshIfNeeded($conn);
        return $this->revenue->fetch($conn, $date);
    }

    public function healthCheck(PlatformConnection $conn): bool
    {
        $this->refreshIfNeeded($conn);
        $accessToken = (string) ($conn->credentials['access_token'] ?? '');
        if ($accessToken === '' || $conn->external_id === null) {
            return false;
        }

        try {
            $client = new ShopifyClient((string) $conn->external_id, $accessToken);
            $data = $client->graphql('{ shop { name } }');
            return ! empty($data['shop']['name']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Public so jobs (SyncBrandHistoryJob, SyncBrandDayJob) can refresh the
     * access token before paginating. The OAuthService is the source of truth
     * for refresh — we just keep the connection model in sync with whatever
     * it gives back.
     */
    public function refreshIfNeeded(PlatformConnection $conn): void
    {
        $creds   = $conn->credentials ?? [];
        $expires = (string) ($creds['expires_at'] ?? '');
        $refresh = (string) ($creds['refresh_token'] ?? '');
        $type    = (string) ($conn->metadata['connection_type'] ?? '');

        // Admin custom-app tokens are non-expiring by design (per
        // docs/05-platforms/shopify-store-onboarding.md). They have no
        // refresh_token and no expires_at and don't need rotation.
        if ($type === 'admin_custom_app') {
            return;
        }

        // No expires_at AND no refresh_token = a non-expiring token issued
        // by a Partner app that's not configured for expiring offline tokens.
        // It still might work against the Admin API; if it doesn't, the actual
        // GraphQL call surfaces the deprecation error with Shopify's wording.
        // Refusing to use it here would block every operator with a working
        // legacy install — better to let the call run and report ground truth.
        if ($expires === '' && $refresh === '') {
            return;
        }

        // Not expired yet — keep using current token.
        if ($expires !== '' && CarbonImmutable::parse($expires)->isFuture()) {
            return;
        }

        // Expired — refresh in place. Throws if Shopify rejects the refresh.
        $fresh = $this->oauth->refreshAccessToken($conn);
        $conn->forceFill(['credentials' => $fresh])->save();
    }
}
