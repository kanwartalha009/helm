<?php

declare(strict_types=1);

namespace App\Platforms\Meta;

use App\Services\PlatformCredentialService;

/**
 * Owns every outbound HTTP call to the Meta Marketing API. Reads the
 * System User token via PlatformCredentialService::get('meta', 'system_user_token').
 * Honors X-Business-Use-Case-Usage and error codes 17 / 4 with exponential backoff.
 */
final class MetaClient
{
    public function __construct(
        private readonly PlatformCredentialService $credentials,
    ) {}
}
