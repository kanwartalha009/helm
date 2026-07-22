<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trusted devices for 2FA (Kanwar, 2026-07-22 — "keep browser device history so
 * we don't need the auth code every login"). After a successful MFA challenge
 * with "Trust this device" ticked, we mint a long-lived opaque token, store only
 * its SHA-256 hash here, and hand the raw token to the browser. On the next login
 * the browser replays it; a matching, unexpired row lets that user skip the code
 * on that device only (password is still required — this replaces the SECOND
 * factor, never the first).
 *
 * Additive + non-destructive per the standing rule (production is live).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // SHA-256 hex of the raw device token — the raw value is NEVER stored,
            // exactly like Sanctum tokens and recovery codes.
            $table->string('token_hash', 64)->unique();
            // Human label derived from the user agent ("Chrome on macOS") so the
            // device list in Settings is recognisable.
            $table->string('label')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            // Fixed trust window (14 days) from when the device was trusted; after
            // this the browser is challenged for a code again.
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_trusted_devices');
    }
};
