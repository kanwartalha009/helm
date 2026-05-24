<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-run onboarding state plus avatar storage path.
 * `avatar_path` is the public-disk-relative path; the resource builds the
 * full URL when serializing the user.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestampTz('onboarding_completed_at')->nullable();
            $t->string('avatar_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['onboarding_completed_at', 'avatar_path']);
        });
    }
};
