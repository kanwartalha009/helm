<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_credentials', function (Blueprint $t) {
            $t->bigIncrements('id');
            // platform: meta | google | tiktok (NOT shopify — that's per-brand in platform_connections)
            $t->string('platform', 30);
            // key: 'system_user_token', 'mcc_refresh_token', 'developer_token', etc.
            $t->string('key', 60);
            // value: encrypted at application layer via Laravel's `encrypted` cast
            $t->text('value');
            $t->string('label', 120)->nullable();
            $t->jsonb('metadata')->nullable();
            // status: active | rotated | revoked
            $t->string('status', 20)->default('active');
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->index(['platform', 'status']);
        });

        // Partial unique index — only one ACTIVE row per (platform, key) at a time.
        // Rotated/revoked rows are kept for historical sync log explanations.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('platform_credentials', function (Blueprint $table) {
                $table->string('active_key')
                    ->virtualAs("CASE WHEN status = 'active' THEN `key` ELSE NULL END")
                    ->nullable();
                $table->unique(['platform', 'active_key'], 'platform_credentials_active_unique');
            });
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX platform_credentials_active_unique '
                . 'ON platform_credentials (platform, key) '
                . "WHERE status = 'active'"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_credentials');
    }
};
