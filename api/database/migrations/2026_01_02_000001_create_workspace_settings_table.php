<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple key/value store for workspace-wide settings (workspace name,
 * primary blended currency, etc.). Singleton-ish — one row per key,
 * shared across the whole tenant since Helm is single-tenant by design.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('key', 60)->unique();
            $t->jsonb('value');
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};
