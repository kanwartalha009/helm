<?php

declare(strict_types=1);

namespace App\Platforms\Google;

use App\Services\PlatformCredentialService;

/**
 * Owns every outbound call to the Google Ads API. Reads:
 *   PlatformCredentialService::get('google', 'refresh_token')
 *   PlatformCredentialService::get('google', 'client_id')
 *   PlatformCredentialService::get('google', 'client_secret')
 *   PlatformCredentialService::get('google', 'developer_token')
 *   PlatformCredentialService::get('google', 'login_customer_id')
 *
 * Uses the official google-ads/google-ads-php SDK which handles QuotaError
 * and exponential backoff internally. Configure max retries to 3.
 */
final class GoogleAdsClient
{
    public function __construct(
        private readonly PlatformCredentialService $credentials,
    ) {}
}
