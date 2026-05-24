<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Meta adapter. Uses one platform-level System User token that covers every
 * ad account under the agency's Business Manager.
 *
 * Reads `PlatformCredentialService::get('meta', 'system_user_token')` at call time.
 */
final class MetaAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly MetaClient $client,
        private readonly InsightsFetcher $insights,
        private readonly PlatformCredentialService $credentials,
    ) {}

    public function key(): string
    {
        return 'meta';
    }

    public function label(): string
    {
        return 'Meta Ads';
    }

    public function authUrl(Brand $brand): string
    {
        // Meta is platform-level — no per-brand OAuth. The agency picks an
        // ad account via listAvailableAccounts() instead.
        throw new RuntimeException('Not yet implemented');
    }

    /** @param array<string, mixed> $payload */
    public function handleCallback(Brand $brand, array $payload): PlatformConnection
    {
        throw new RuntimeException('Not yet implemented');
    }

    /** @return array<int, array{external_id: string, name: string, currency: string}> */
    public function listAvailableAccounts(PlatformConnection $conn): array
    {
        // Hits GET /me/businesses/{id}/owned_ad_accounts using the BM System User token.
        throw new RuntimeException('Not yet implemented');
    }

    public function attachAccount(PlatformConnection $conn, string $externalId): void
    {
        throw new RuntimeException('Not yet implemented');
    }

    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        return $this->insights->fetch($conn, $date);
    }

    public function healthCheck(PlatformConnection $conn): bool
    {
        throw new RuntimeException('Not yet implemented');
    }
}
