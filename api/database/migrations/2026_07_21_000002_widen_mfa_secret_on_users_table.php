<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen users.mfa_secret from VARCHAR(255) to TEXT (Kanwar, 2026-07-21).
 *
 * The column was too small for the encrypted value: an app-layer-encrypted
 * 32-char TOTP secret serialises to ~256 chars — one over the 255 limit — so
 * every enrollment INSERT/UPDATE failed on MySQL with
 * "SQLSTATE[22001] Data too long for column 'mfa_secret'". This is the real
 * reason 2FA "never worked"; it was invisible in tests because SQLite doesn't
 * enforce VARCHAR length. TEXT gives ample headroom (and matches
 * mfa_recovery_codes, which is already TEXT). Non-destructive — widening only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('mfa_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('mfa_secret', 255)->nullable()->change();
        });
    }
};
