<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2FA recovery codes (Kanwar, 2026-07-21). Additive, nullable — encrypted at
 * the application layer (see User::$casts) and holding an array of BCRYPT
 * HASHES, never the plaintext codes. Null = the user has none yet (e.g. no
 * secret, or enrolled before recovery codes existed and hasn't regenerated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mfa_recovery_codes');
        });
    }
};
