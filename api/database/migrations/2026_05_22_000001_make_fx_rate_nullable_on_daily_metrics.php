<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decouples sync from the FX provider.
 *
 * Original spec rule (§7) had `fx_rate_to_usd` NOT NULL, stamped at sync
 * time. In practice that means every sync depends on a third-party FX
 * API being up — at 80+ stores syncing daily this is an unacceptable
 * single point of failure. We're now treating FX as a presentation
 * concern: sync writes native currency unconditionally; an async
 * BackfillFxRatesJob fills in `fx_rate_to_usd` when rates land.
 *
 * Rows that arrived before FX was available are flagged via
 * `metadata->>'fx_pending' = 'true'` so the backfill job knows what
 * to revisit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_metrics', function (Blueprint $t) {
            $t->decimal('fx_rate_to_usd', 14, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No-op for safety: existing NULL rows would block re-applying NOT NULL.
        // If you really need to roll back, backfill FX first then ALTER manually.
    }
};
