<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent repair: creates tables that should exist per the migration set
 * but are physically missing. Survives the case where `php artisan migrate`
 * reports "Nothing to migrate" while the tables themselves were dropped or
 * never finished creating (manual DB resets, half-rolled-back migrations).
 *
 *   php artisan helm:repair:tables
 *
 * Safe to re-run — every CREATE is wrapped in `hasTable()` so existing
 * tables are left alone.
 */
class HelmRepairTablesCommand extends Command
{
    protected $signature = 'helm:repair:tables';

    protected $description = 'Create any tables the app expects but the database is missing.';

    public function handle(): int
    {
        $created = [];

        if (! Schema::hasTable('brand_user_access')) {
            Schema::create('brand_user_access', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $t->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestampsTz();
                $t->unique(['user_id', 'brand_id']);
                $t->index('brand_id');
            });
            $created[] = 'brand_user_access';
        }

        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $t) {
                $t->string('email')->primary();
                $t->string('token');
                $t->timestampTz('created_at')->nullable();
            });
            $created[] = 'password_reset_tokens';
        }

        if (! Schema::hasTable('invitations')) {
            Schema::create('invitations', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('email', 190);
                $t->string('role', 30);
                $t->string('token', 64)->unique();
                $t->text('note')->nullable();
                $t->jsonb('brand_ids')->nullable();
                $t->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestampTz('expires_at');
                $t->timestampTz('accepted_at')->nullable();
                $t->timestampTz('revoked_at')->nullable();
                $t->timestampsTz();
                $t->index('email');
                $t->index('expires_at');
            });
            $created[] = 'invitations';
        }

        // platform_connections.credentials needs to be TEXT, not JSONB, because
        // the model casts it as encrypted:array (ciphertext is not valid JSON).
        // If the column is still JSONB, switch it here.
        if (Schema::hasTable('platform_connections')) {
            $type = DB::selectOne(
                "SELECT data_type FROM information_schema.columns "
                . "WHERE table_name = 'platform_connections' AND column_name = 'credentials'"
            );
            if ($type && stripos($type->data_type, 'json') !== false) {
                DB::statement('ALTER TABLE platform_connections ALTER COLUMN credentials TYPE TEXT USING credentials::text');
                $created[] = 'platform_connections.credentials → TEXT';
            }
        }

        if (empty($created)) {
            $this->info('All expected tables already exist.');
            return self::SUCCESS;
        }

        $this->info('Repaired: ' . implode(', ', $created));
        $this->newLine();
        $this->comment('Tip: if `php artisan migrate` still reports "Nothing to migrate", these tables are now correct.');
        return self::SUCCESS;
    }
}
