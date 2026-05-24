<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * TikTok adapter. Uses one Business Center long-lived token that covers
 * every advertiser under the agency BC.
 */
final class TikTokAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly TikTokClient $client,
        private readonly ReportsFetcher $reports,
        private readonly PlatformCredentialService $credentials,
    ) {}

    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok Ads';
    }

    public function authUrl(Brand $brand): string
    {
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
        // Calls /v1.3/bc/advertiser/get/ using the BC token.
        throw new RuntimeException('Not yet implemented');
    }

    public function attachAccount(PlatformConnection $conn, string $externalId): void
    {
        throw new RuntimeException('Not yet implemented');
    }

    public function fetchDay(PlatformConnection $conn, CarbonImmutable $date): MetricSnapshot
    {
        return $this->reports->fetch($conn, $date);
    }

    public function healthCheck(PlatformConnection $conn): bool
    {
        throw new RuntimeException('Not yet implemented');
    }
}
