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

    public function test_trusting_a_device_skips_the_code_on_the_next_login(): void
    {
        // Kanwar, 2026-07-22 — "keep browser device history so we don't need the
        // code every login". A challenge with remember_device=true mints a
        // trusted-device token; replaying it on the next login skips the code.
        $g = new Google2FA();
        $secret = $g->generateSecretKey(32);
        $user = $this->user(['mfa_secret' => $secret]);

        $pending = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('pending_token');
        $trusted = $this->postJson('/api/auth/mfa/challenge', [
            'pending_token'   => $pending,
            'code'            => $this->totp($secret),
            'remember_device' => true,
        ])->assertOk()->json('trusted_device_token');

        $this->assertNotEmpty($trusted);
        $this->assertDatabaseCount('mfa_trusted_devices', 1);
        // Stored hashed, never raw.
        $this->assertDatabaseMissing('mfa_trusted_devices', ['token_hash' => $trusted]);

        // Next login WITH the token → straight in, no challenge, real token issued.
        $this->postJson('/api/auth/login', [
            'email'                => $user->email,
            'password'             => self::PASSWORD,
            'trusted_device_token' => $trusted,
        ])
            ->assertOk()
            ->assertJsonPath('trusted_device', true)
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonMissingPath('mfa_required');
    }

    public function test_a_login_without_remember_still_challenges_next_time(): void
    {
        $g = new Google2FA();
        $secret = $g->generateSecretKey(32);
        $user = $this->user(['mfa_secret' => $secret]);

        $pending = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('pending_token');
        $res = $this->postJson('/api/auth/mfa/challenge', ['pending_token' => $pending, 'code' => $this->totp($secret)])
            ->assertOk();
        $this->assertNull($res->json('trusted_device_token'));
        $this->assertDatabaseCount('mfa_trusted_devices', 0);

        // No token to present → normal challenge again.
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('mfa_required', true);
    }

    public function test_an_expired_or_foreign_trusted_token_does_not_skip_the_code(): void
    {
        $g = new Google2FA();
        $user  = $this->user(['mfa_secret' => $g->generateSecretKey(32)]);
        $other = $this->user(['mfa_secret' => $g->generateSecretKey(32), 'email' => 'other@helm.test']);

        // An EXPIRED device for the real user.
        $expiredRaw = \App\Models\MfaTrustedDevice::newRawToken();
        \App\Models\MfaTrustedDevice::create([
            'user_id' => $user->id, 'token_hash' => \App\Models\MfaTrustedDevice::hash($expiredRaw),
            'label' => 'Old', 'expires_at' => now()->subDay(),
        ]);
        // A LIVE device that belongs to someone else.
        $foreignRaw = \App\Models\MfaTrustedDevice::newRawToken();
        \App\Models\MfaTrustedDevice::create([
            'user_id' => $other->id, 'token_hash' => \App\Models\MfaTrustedDevice::hash($foreignRaw),
            'label' => 'Theirs', 'expires_at' => now()->addDays(14),
        ]);

        foreach ([$expiredRaw, $foreignRaw, 'totally-made-up'] as $tok) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email, 'password' => self::PASSWORD, 'trusted_device_token' => $tok,
            ])->assertOk()->assertJsonPath('mfa_required', true);
        }
    }

    public function test_changing_the_password_revokes_trusted_devices(): void
    {
        $g = new Google2FA();
        $user = $this->user(['mfa_secret' => $g->generateSecretKey(32)]);
        \App\Models\MfaTrustedDevice::create([
            'user_id' => $user->id, 'token_hash' => \App\Models\MfaTrustedDevice::hash('x'),
            'label' => 'Chrome on macOS', 'expires_at' => now()->addDays(14),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/password', [
            'current_password'          => self::PASSWORD,
            'new_password'              => 'a-brand-new-passphrase',
            'new_password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertDatabaseCount('mfa_trusted_devices', 0);
    }

    public function test_user_can_list_and_revoke_their_trusted_devices(): void
    {
        $g = new Google2FA();
        $user = $this->user(['mfa_secret' => $g->generateSecretKey(32)]);
        $live = \App\Models\MfaTrustedDevice::create([
            'user_id' => $user->id, 'token_hash' => \App\Models\MfaTrustedDevice::hash('live'),
            'label' => 'Chrome on macOS', 'last_used_at' => now(), 'expires_at' => now()->addDays(14),
        ]);
        // An expired row is pruned by the list call, never shown as still trusted.
        \App\Models\MfaTrustedDevice::create([
            'user_id' => $user->id, 'token_hash' => \App\Models\MfaTrustedDevice::hash('dead'),
            'label' => 'Old', 'expires_at' => now()->subDay(),
        ]);
        Sanctum::actingAs($user);

        $list = $this->getJson('/api/auth/trusted-devices')->assertOk()->json('devices');
        $this->assertCount(1, $list);
        $this->assertSame('Chrome on macOS', $list[0]['label']);
        $this->assertArrayNotHasKey('token_hash', $list[0]); // never leak the hash

        $this->deleteJson("/api/auth/trusted-devices/{$live->id}")->assertNoContent();
        $this->assertDatabaseCount('mfa_trusted_devices', 0);
    }

    public function test_setup_uses_the_white_label_agency_name_as_the_totp_issuer(): void
    {
        \App\Models\WorkspaceSetting::setValue('report_branding', ['agency_name' => 'Acme Ads']);

        $user = $this->user(['mfa_secret' => null]);
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/auth/mfa/setup')->assertOk();
        $this->assertSame('Acme Ads', $res->json('issuer'));
        // The otpauth URI the QR encodes carries the white-label issuer, not "Helm".
        $this->assertStringContainsString('Acme%20Ads', $res->json('otpauthUrl'));
        $this->assertStringNotContainsStringIgnoringCase('helm', $res->json('otpauthUrl'));
    }
}
