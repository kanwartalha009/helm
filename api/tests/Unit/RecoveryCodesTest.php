<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RecoveryCodes;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

/**
 * 2FA recovery codes (Kanwar, 2026-07-21) — generation, hashing, single-use
 * consumption, and the TOTP-vs-recovery discriminator.
 */
class RecoveryCodesTest extends TestCase
{
    public function test_generate_returns_the_expected_count_of_formatted_codes(): void
    {
        $codes = RecoveryCodes::generate();

        $this->assertCount(RecoveryCodes::COUNT, $codes);
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $c);
        }
        // No ambiguous characters (0/1/o/l/i) anywhere.
        $this->assertDoesNotMatchRegularExpression('/[01oli]/', implode('', $codes));
    }

    public function test_a_code_verifies_against_its_hash_regardless_of_dashes_or_case(): void
    {
        $plain  = RecoveryCodes::generate(1);
        $hashes = RecoveryCodes::hashAll($plain);

        // Same code typed with different punctuation / case still consumes.
        $typed  = strtoupper(str_replace('-', ' ', $plain[0]));
        $remaining = RecoveryCodes::consume($hashes, $typed);

        $this->assertNotNull($remaining);
        $this->assertCount(0, $remaining);
    }

    public function test_consume_removes_only_the_matched_code_and_is_single_use(): void
    {
        $plain  = RecoveryCodes::generate(3);
        $hashes = RecoveryCodes::hashAll($plain);

        $afterFirst = RecoveryCodes::consume($hashes, $plain[1]);
        $this->assertNotNull($afterFirst);
        $this->assertCount(2, $afterFirst);

        // The same code cannot be used again against the reduced set.
        $this->assertNull(RecoveryCodes::consume($afterFirst, $plain[1]));
        // A different code still works.
        $this->assertNotNull(RecoveryCodes::consume($afterFirst, $plain[0]));
    }

    public function test_consume_returns_null_for_an_unknown_code(): void
    {
        $hashes = RecoveryCodes::hashAll(RecoveryCodes::generate(2));
        $this->assertNull(RecoveryCodes::consume($hashes, 'zzzzz-zzzzz'));
        $this->assertNull(RecoveryCodes::consume($hashes, ''));
    }

    public function test_looks_like_recovery_code_distinguishes_totp_from_recovery(): void
    {
        $this->assertFalse(RecoveryCodes::looksLikeRecoveryCode('123456'));   // TOTP
        $this->assertFalse(RecoveryCodes::looksLikeRecoveryCode('12'));       // too short for either
        $this->assertTrue(RecoveryCodes::looksLikeRecoveryCode('abcde-fghij'));
        $this->assertTrue(RecoveryCodes::looksLikeRecoveryCode('ABCDE FGHIJ'));
    }
}
