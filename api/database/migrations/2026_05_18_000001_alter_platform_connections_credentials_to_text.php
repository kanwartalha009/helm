<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `credentials` column was originally JSONB, but the model casts it with
 * `encrypted:array` — Laravel JSON-encodes then encrypts with APP_KEY, producing
 * an opaque ciphertext string that is not valid JSON. Postgres rejects the
 * insert with SQLSTATE[22P02]. Encrypted casts always need a TEXT-shaped column.
 *
 * The cast on metadata stays JSONB because it's a plain `array` cast — no
 * encryption, valid JSON on the wire.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\Schema::table('platform_connections', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->text('credentials')->change();
            });
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE platform_connections ALTER COLUMN credentials TYPE TEXT USING credentials::text');
        }
        // sqlite (test DB): the column is already text-shaped, so nothing to do.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\Schema::table('platform_connections', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->json('credentials')->change();
            });
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE platform_connections ALTER COLUMN credentials TYPE JSONB USING credentials::jsonb');
        }
    }
};
