<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's default password-reset broker stores hashed tokens here. We
 * skipped this in the original scaffold because /forgot wasn't wired; the
 * forgot/reset endpoints in AuthController now depend on it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
