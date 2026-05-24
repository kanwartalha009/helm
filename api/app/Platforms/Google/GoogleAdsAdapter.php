<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Platforms\Contracts\MetricSnapshot;
use App\Platforms\Contracts\PlatformAdapter;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Google Ads adapter. Uses one MCC (manager) OAuth refresh token + developer
 * token. The MCC sees every linked customer account so per-brand auth is not
 * needed — the agency picks a customer ID via listAvailableAccounts().
 */
final class GoogleAdsAdapter implements PlatformAdapter
{
    public function __construct(
        private readonly GoogleAdsClient $client,
        private readonly ReportsFetcher $reports,
        private readonly PlatformCredentialService $credentials,
    ) {}

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Ads';
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
        // ListAccessibleCustomers under the MCC via login_customer_id.
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
