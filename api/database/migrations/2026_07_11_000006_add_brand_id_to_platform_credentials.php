<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GO-1.1: brand-scope platform_credentials so a PER-BRAND secret (Klaviyo private
 * key — every brand has its own Klaviyo account) can live beside the agency-level
 * tokens (Meta/Google/TikTok/LLM, which stay brand_id NULL). ADR: this extends the
 * table beyond the agency-level assumption in the original migration.
 *
 * Additive + non-destructive: adds a nullable brand_id (existing rows become NULL =
 * agency-level, unchanged) and BROADENS the "one active row per (platform,key)"
 * partial-unique to "one active row per (platform, brand, key)" — agency rows fold
 * to brand 0. No data is dropped; the tiny config table rebuilds its index instantly.
 * Reads for agency creds pass no brand_id → WHERE brand_id IS NULL → identical results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_credentials', function (Blueprint $t): void {
            $t->foreignId('brand_id')->nullable()->after('key')->constrained('brands')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            // Replace the (platform, active_key) unique with a brand-aware active
            // scope: CONCAT(brand|key) only while active. brand_id NULL → 0 so the
            // single-active guarantee still holds for agency creds.
            Schema::table('platform_credentials', function (Blueprint $t): void {
                $t->dropUnique('platform_credentials_active_unique');
            });
            Schema::table('platform_credentials', function (Blueprint $t): void {
                $t->dropColumn('active_key');
            });
            Schema::table('platform_credentials', function (Blueprint $t): void {
                $t->string('active_scope')
                    ->virtualAs("CASE WHEN status = 'active' THEN CONCAT(COALESCE(brand_id, 0), '|', `key`) ELSE NULL END")
                    ->nullable();
                $t->unique(['platform', 'active_scope'], 'platform_credentials_active_scope_unique');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS platform_credentials_active_unique');
            DB::statement(
                'CREATE UNIQUE INDEX platform_credentials_active_unique '
                . 'ON platform_credentials (platform, COALESCE(brand_id, 0), key) '
                . "WHERE status = 'active'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('platform_credentials', function (Blueprint $t): void {
                $t->dropUnique('platform_credentials_active_scope_unique');
            });
            Schema::table('platform_credentials', function (Blueprint $t): void {
                $t->dropColumn('active_scope');
            });
            Schema::table('platform_credentials', function (Blueprint $t): void {
                $t->string('active_key')
                    ->virtualAs("CASE WHEN status = 'active' THEN `key` ELSE NULL END")
                    ->nullable();
                $t->unique(['platform', 'active_key'], 'platform_credentials_active_unique');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS platform_credentials_active_unique');
            DB::statement(
                'CREATE UNIQUE INDEX platform_credentials_active_unique '
                . 'ON platform_credentials (platform, key) '
                . "WHERE status = 'active'"
            );
        }

        Schema::table('platform_credentials', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('brand_id');
        });
    }
};
