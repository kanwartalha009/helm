<?php

declare(strict_types=1);

namespace App\Platforms\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Throwable;

/**
 * Classifies a sync exception so SyncBrandDayJob / SyncBrandHistoryJob can
 * decide whether to mark the platform_connection as `errored` (forcing the
 * user to re-OAuth) or just record `last_error` while keeping the
 * connection healthy.
 *
 * Rule of thumb:
 *   - Auth failure  → user MUST re-do the OAuth flow. Mark errored.
 *   - Transient     → the connection is fine; the data fetch hit a snag.
 *                     Leave status=active, record last_error, retry on
 *                     the next scheduled sync.
 *
 * Past mistake (May 2026 incident): every sync exception flipped the
 * connection to `errored`, surfacing "Disconnect and re-add with a fresh
 * token" in the UI even for unrelated bugs (missing FX rate, GraphQL
 * parse hiccup, network blip). Re-OAuth shouldn't be on the hot path.
 */
final class SyncFailureClassifier
{
    /** Substrings (case-insensitive) that indicate the OAuth credentials no longer work. */
    private const AUTH_SUBSTRINGS = [
        'invalid api key or access token',
        'unrecognized login',
        'wrong password',
        'invalid_grant',
        'invalidgrant',
        'token has been revoked',
        'app uninstalled',
        'app_uninstalled',
        'non-expiring access tokens are no longer accepted',
        'missing access_token',
        'shopify connection is missing access_token',
        'this shopify connection is missing token expiration',
        '401 unauthorized',
        '403 forbidden',
        'access denied',
        'insufficient_scope',
    ];

    public static function isAuthFailure(Throwable $e): bool
    {
        // Decrypt failure = APP_KEY drift or tampered ciphertext → the
        // stored credentials can't be used, must re-OAuth.
        if ($e instanceof DecryptException) {
            return true;
        }

        // Walk the exception chain so a wrapped Shopify error is still
        // recognised (FxService wraps provider exceptions, etc.).
        $cursor = $e;
        while ($cursor !== null) {
            $msg = strtolower($cursor->getMessage());
            foreach (self::AUTH_SUBSTRINGS as $needle) {
                if (str_contains($msg, $needle)) {
                    return true;
                }
            }
            $cursor = $cursor->getPrevious();
        }

        return false;
    }

    /**
     * @return 'errored'|'active'  The status to write back to platform_connections.
     */
    public static function connectionStatusFor(Throwable $e): string
    {
        return self::isAuthFailure($e) ? 'errored' : 'active';
    }
}
