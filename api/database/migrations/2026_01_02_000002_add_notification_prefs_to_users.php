<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification preferences as a JSONB column. Defaults to
 * { daily_sync_digest: true, connection_errored: true, ticket_assigned: false, weekly_summary: false }.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->jsonb('notification_prefs')->nullable();
            $t->string('display_initials', 4)->nullable();
            $t->string('timezone', 64)->default('UTC');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['notification_prefs', 'display_initials', 'timezone']);
        });
    }
};
