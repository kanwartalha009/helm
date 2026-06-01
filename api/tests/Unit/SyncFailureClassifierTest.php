<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Platforms\Support\SyncFailureClassifier;
use Illuminate\Contracts\Encryption\DecryptException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure unit test — no Laravel boot, no database. Guards the rule that only
 * genuine auth failures flip a connection to `errored` (forcing re-OAuth),
 * while transient errors keep it `active`. This is the exact behaviour the
 * May 2026 incident regressed, so it must never drift silently again.
 */
final class SyncFailureClassifierTest extends TestCase
{
    #[DataProvider('authFailureMessages')]
    public function test_auth_failures_are_classified_as_errored(string $message): void
    {
        $e = new RuntimeException($message);

        $this->assertTrue(SyncFailureClassifier::isAuthFailure($e));
        $this->assertSame('errored', SyncFailureClassifier::connectionStatusFor($e));
    }

    /** @return array<int, array{0: string}> */
    public static function authFailureMessages(): array
    {
        return [
            ['Invalid API key or access token'],
            ['401 Unauthorized'],
            ['403 Forbidden'],
            ['OAuth returned invalid_grant'],
            ['app_uninstalled'],
            ['This Shopify connection is missing access_token'],
            ['insufficient_scope for this request'],
        ];
    }

    #[DataProvider('transientFailureMessages')]
    public function test_transient_failures_keep_the_connection_active(string $message): void
    {
        $e = new RuntimeException($message);

        $this->assertFalse(SyncFailureClassifier::isAuthFailure($e));
        $this->assertSame('active', SyncFailureClassifier::connectionStatusFor($e));
    }

    /** @return array<int, array{0: string}> */
    public static function transientFailureMessages(): array
    {
        return [
            ['cURL error 28: Operation timed out'],
            ['GraphQL query cost exceeded, throttled'],
            ['Missing FX rate for EUR on 2026-05-30'],
            ['500 Internal Server Error'],
        ];
    }

    public function test_decrypt_exception_forces_reauth(): void
    {
        $e = new DecryptException('The MAC is invalid.');

        $this->assertTrue(SyncFailureClassifier::isAuthFailure($e));
        $this->assertSame('errored', SyncFailureClassifier::connectionStatusFor($e));
    }

    public function test_wrapped_auth_failure_is_detected_through_the_exception_chain(): void
    {
        $inner = new RuntimeException('app_uninstalled');
        $outer = new RuntimeException('SyncBrandDayJob failed for brand 12', 0, $inner);

        $this->assertTrue(SyncFailureClassifier::isAuthFailure($outer));
    }
}
