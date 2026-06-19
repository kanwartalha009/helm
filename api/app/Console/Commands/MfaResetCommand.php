<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Emergency MFA reset — clears a user's TOTP secret so they fall back to
 * password-only login. This is the anti-lockout escape hatch for mandatory
 * master_admin MFA: if the sole admin loses their authenticator, run this on
 * the server to recover. master_admin will be re-prompted to enroll on the
 * next login.
 *
 *   php artisan mfa:reset kanwar@example.com
 *   php artisan mfa:reset 1
 */
class MfaResetCommand extends Command
{
    protected $signature = 'mfa:reset {user : Email address or numeric id of the user}';

    protected $description = "Clear a user's MFA secret (recovery / anti-lockout).";

    public function handle(): int
    {
        $key = (string) $this->argument('user');

        $user = ctype_digit($key)
            ? User::find((int) $key)
            : User::query()->where('email', $key)->first();

        if (! $user) {
            $this->error("No user matches \"{$key}\".");

            return self::FAILURE;
        }

        if (! $user->mfa_secret) {
            $this->info("{$user->email} has no MFA secret set — nothing to clear.");

            return self::SUCCESS;
        }

        $user->update(['mfa_secret' => null]);

        AuditLog::create([
            'actor_user_id' => null,
            'action'        => 'mfa.reset_via_cli',
            'target_type'   => 'user',
            'target_id'     => $user->id,
            'metadata'      => ['email' => $user->email],
            'ip'            => null,
            'user_agent'    => 'artisan',
        ]);

        $this->info("MFA cleared for {$user->email}. They can sign in with a password only; a master_admin will be prompted to re-enroll on next login.");

        return self::SUCCESS;
    }
}
