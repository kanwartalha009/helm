<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-brand Shopify app credentials. Shopify's Partner Dashboard
 * custom-distribution apps are scoped to one Plus organization, which means
 * different brands generally need different Partner apps and therefore
 * different Client ID + Secret pairs. Storing them on the brand makes the
 * OAuth handshake self-contained and removes the workspace-wide
 * `platform_credentials` Shopify section as a coordination point.
 *
 * TEXT (not JSONB) because the model casts it with `encrypted:array` —
 * ciphertext isn't valid JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $t) {
            $t->text('shopify_app')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $t) {
            $t->dropColumn('shopify_app');
        });
    }
};
