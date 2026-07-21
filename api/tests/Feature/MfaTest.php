<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\RecoveryCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Effective 2FA (Kanwar, 2026-07-21): NTP-corrected TOTP, single-use recovery
 * codes, replay protection, and verification rate limiting — the full flow the
 * clock-drift blocker had left switched off.
 *
 * NTP is disabled in the test env (phpunit.xml HELM_MFA_NTP=false), so the
 * offset is 0 and the corrected step equals the system step — except the one
 * test that injects a cached offset to prove the correction is actually wired.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function user(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role'     => 'master_admin',
            'status'   => 'active',
            'password' => self::PASSWORD,
        ], $attrs));
    }

    private function totp(string $secret, ?int $step = null): string
    {
        $g = new Google2FA();

        return $step === null ? $g->getCurrentOtp($secret) : $g->oathTotp($secret, $step);
    }

    public function test_enrollment_confirms_the_code_and_returns_one_time_recovery_codes(): void
    {
        $user = $this->user(['mfa_secret' => null]);
        Sanctum::actingAs($user);

        $secret = $this->postJson('/api/auth/mfa/setup')->assertOk()->json('secret');
        $this->assertNotEmpty($secret);

        $res = $this->postJson('/api/auth/mfa/verify', ['code' => $this->totp($secret)])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $codes = $res->json('recoveryCodes');
        $this->assertCount(RecoveryCodes::COUNT, $codes);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes[0]);

        // Secret persisted (encrypted), recovery codes stored as hashes, and the
        // /me contract reports the remaining count.
        $user->refresh();
        $this->assertNotNull($user->mfa_secret);
        $this->assertCount(RecoveryCodes::COUNT, $user->mfa_recovery_codes);
        $this->assertNotSame($codes[0], $user->mfa_recovery_codes[0]); // stored hashed, not plaintext
        $this->assertSame(RecoveryCodes::COUNT, $this->getJson('/api/auth/me')->json('mfaRecoveryCodesRemaining'));
    }

    public function test_login_challenge_with_a_valid_totp_issues_a_token(): void
    {
        $g = new Google2FA();
        $secret = $g->generateSecretKey(32);
        $user = $this->user(['mfa_secret' => $secret]);

        // Password alone → challenge, no token yet.
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('mfa_required', true);
        $pending = $login->json('pending_token');
        $this->assertNotEmpty($pending);

        $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $pending, 'code' => $this->totp($secret)])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_challenge_accepts_and_consumes_a_recovery_code(): void
    {
        $g = new Google2FA();
        $secret = $g->generateSecretKey(32);
        $plain  = RecoveryCodes::generate();
        $user = $this->user(['mfa_secret' => $secret, 'mfa_recovery_codes' => RecoveryCodes::hashAll($plain)]);

        $pending = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->json('pending_token');

        $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $pending, 'code' => $plain[0]])
            ->assertOk()
            ->assertJsonPath('recoveryUsed', true)
            ->assertJsonStructure(['user', 'token']);

        // The code was consumed — one fewer remains, and it can't be reused.
        $user->refresh();
        $this->assertCount(RecoveryCodes::COUNT - 1, $user->mfa_recovery_codes);

        $pending2 = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->json('pending_token');
        $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $pending2, 'code' => $plain[0]])
            ->assertStatus(422);
    }

    public function test_a_totp_code_cannot_be_replayed(): void
    {
        $g = new Google2FA();
        $secret = $g->generateSecretKey(32);
        $user = $this->user(['mfa_secret' => $secret]);
        $code = $this->totp($secret);

        $pending1 = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('pending_token');
        $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $pending1, 'code' => $code])->assertOk();

        // Same code, fresh challenge → rejected by replay protection (the step
        // was already consumed), never a second valid sign-in.
        $pending2 = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('pending_token');
        $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $pending2, 'code' => $code])->assertStatus(422);
    }

    public function test_enrollment_verification_locks_after_five_failed_attempts(): void
    {
        $user = $this->user(['mfa_secret' => null]);
        Sanctum::actingAs($user);
        $this->postJson('/api/auth/mfa/setup')->assertOk();

        // Five wrong codes → 422 each; the sixth is rate-limited (429).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/auth/mfa/verify', ['code' => '000000'])->assertStatus(429);
    }

    public function test_ntp_offset_shifts_the_accepted_totp_window(): void
    {
        // Prove the app-level clock correction is actually applied: with a +90s
        // (three-step) offset injected, a code minted for the SYSTEM clock is
        // rejected while a code minted for the CORRECTED clock is accepted.
        config(['helm.mfa.ntp.enabled' => true]);
        Cache::put('helm.mfa.ntp_offset', 90, now()->addHour());

        $g = new Google2FA();
        $secret = $g->generateSecretKey(32);
        $user = $this->user(['mfa_secret' => $secret]);

        $systemCode  = $this->totp($secret); // for floor(now/30)
        $correctStep = (int) floor((time() + 90) / 30);
        $correctCode = $this->totp($secret, $correctStep);

        // System-clock code is 3 steps off the corrected clock → outside ±1 → 422.
        $p1 = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('pending_token');
        $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $p1, 'code' => $systemCode])->assertStatus(422);

        // Corrected-clock code is accepted.
        $p2 = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('pending_token');
        $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $p2, 'code' => $correctCode])->assertOk();
    }

    public function test_regenerate_recovery_codes_requires_the_current_password(): void
    {
        $g = new Google2FA();
        $plain = RecoveryCodes::generate();
        $user = $this->user(['mfa_secret' => $g->generateSecretKey(32), 'mfa_recovery_codes' => RecoveryCodes::hashAll($plain)]);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/mfa/recovery-codes', ['current_password' => 'wrong'])->assertStatus(422);

        $fresh = $this->postJson('/api/auth/mfa/recovery-codes', ['current_password' => self::PASSWORD])
            ->assertOk()
            ->json('recoveryCodes');
        $this->assertCount(RecoveryCodes::COUNT, $fresh);

        // Old codes no longer work; the new set replaced them.
        $user->refresh();
        $this->assertNull(RecoveryCodes::consume($user->mfa_recovery_codes, $plain[0]));
        $this->assertNotNull(RecoveryCodes::consume($user->mfa_recovery_codes, $fresh[0]));
    }

    public function test_disable_clears_the_secret_and_recovery_codes(): void
    {
        $g = new Google2FA();
        $user = $this->user(['mfa_secret' => $g->generateSecretKey(32), 'mfa_recovery_codes' => RecoveryCodes::hashAll(RecoveryCodes::generate())]);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/mfa/disable', ['current_password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $user->refresh();
        $this->assertNull($user->mfa_secret);
        $this->assertNull($user->mfa_recovery_codes);
    }
}
