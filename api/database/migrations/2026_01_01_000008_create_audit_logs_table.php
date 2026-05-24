<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only event ledger. Never deleted. Used by the per-action history
 * view and for security review.
 *
 * Per spec §7.2 this lives in Phase 1.5, but the platform_credentials work
 * writes to it from day one, so it ships in Phase 1.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // e.g. 'user.invited', 'permission.granted', 'credential.rotated'
            $t->string('action', 100);
            $t->string('target_type', 60)->nullable();
            $t->unsignedBigInteger('target_id')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['actor_user_id', 'created_at']);
            $t->index(['target_type', 'target_id']);
            $t->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
