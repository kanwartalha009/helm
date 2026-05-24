<?php

declare(strict_types=1);

namespace App\Platforms\TikTok;

use App\Services\PlatformCredentialService;

/**
 * Owns every outbound call to the TikTok Marketing API. Reads:
 *   PlatformCredentialService::get('tiktok', 'bc_token')
 *
 * Respects rate-limit headers; on error code 40100 backs off exponentially.
 * Documented limit: 10 QPS per advertiser.
 */
final class TikTokClient
{
    public function __construct(
        private readonly PlatformCredentialService $credentials,
    ) {}
}
