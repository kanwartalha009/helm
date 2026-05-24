<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.5 RBAC pivot. team_member and brand_user roles get filtered to the
 * brand_ids in this table; master_admin and manager bypass entirely via the
 * Brand global scope (see Brand::booted).
 *
 * One row per (user, brand). Cascade deletes on both sides so revoking a
 * brand or disabling a user leaves nothing dangling.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('brand_user_access', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->unique(['user_id', 'brand_id']);
            $t->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_user_access');
    }
};
