<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_connections', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            // platform: shopify | meta | google | tiktok
            $t->string('platform', 30);
            $t->string('external_id', 190);  // shop domain, ad account id, etc.
            $t->string('display_name', 190)->nullable();
            // encrypted JSONB at application layer
            $t->jsonb('credentials');
            $t->jsonb('metadata')->nullable();
            // status: active | paused | errored
            $t->string('status', 20)->default('active');
            $t->timestampTz('last_sync_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestampsTz();

            $t->unique(['platform', 'external_id']);
            $t->index(['brand_id', 'platform']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
