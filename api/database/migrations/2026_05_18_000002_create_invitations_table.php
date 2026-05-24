<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.5 invitations table. Created upfront so the team CRUD works
 * end-to-end before the larger Phase 1.5 RBAC migration set lands.
 *
 * Spec: docs/08-rbac (Invitations section). Each row is the audit trail for
 * one invitation — accepted/revoked invitations are kept (not deleted) so
 * /audit-log retains the history.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $t) {
            $t->bigIncrements('id');
            // The recipient. Email is the lookup; we don't pre-create a user row.
            $t->string('email', 190);
            // Helm role this invite will grant on acceptance.
            $t->string('role', 30); // manager | team_member | brand_user
            // Cryptographically random one-shot token sent in the email link.
            $t->string('token', 64)->unique();
            // Optional note from the inviter — shown in the email body.
            $t->text('note')->nullable();
            // For team_member / brand_user roles, which brand_ids this user
            // should get access to on acceptance. JSONB so we don't need a
            // separate join table just to stage the intent.
            $t->jsonb('brand_ids')->nullable();
            // Provenance + audit.
            $t->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Lifecycle: pending until either accepted_at or revoked_at is set.
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();

            $t->index('email');
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
