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
        // ALTER directly via raw SQL — Doctrine DBAL isn't installed and we don't
        // need it for a one-column type change. Cast jsonb → text is implicit
        // in Postgres so existing rows (if any) survive the transition.
        DB::statement('ALTER TABLE platform_connections ALTER COLUMN credentials TYPE TEXT USING credentials::text');
    }

    public function down(): void
    {
        // jsonb requires valid JSON in every row, so the reverse cast will fail
        // for any encrypted blob. We accept that — rolling back means the column
        // is empty or the operator handles it manually.
        DB::statement('ALTER TABLE platform_connections ALTER COLUMN credentials TYPE JSONB USING credentials::jsonb');
    }
};
