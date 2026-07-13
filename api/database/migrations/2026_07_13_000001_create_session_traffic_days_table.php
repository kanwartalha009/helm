<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DAY-LEVEL truth for session traffic. One row per brand-day: did we pull it, and did it reconcile?
 *
 * ══ WHY THIS TABLE HAS TO EXIST ══
 * Completeness was being INFERRED from `session_traffic_daily` — "the day is good if it has at
 * least one row with is_complete = true". That collapses three genuinely different states into
 * one signal, and both of the ways it breaks were hit in production:
 *
 *   1. A day with GENUINELY ZERO SESSIONS writes no rows at all. Under the old rule it could never
 *      be counted as complete — so a 30-day window containing one quiet day was blanked FOREVER,
 *      and no backfill could ever fix it, because the backfill was right: the day was already
 *      done, with nothing in it. Unfixable by construction.
 *
 *   2. A day that FAILED to reconcile still writes rows (flagged is_complete = false). The sync
 *      returned "N rows written", the caller read that as success, and the operator was told the
 *      day had been filled while the window stayed blank. The "Fill missing days" button reported
 *      success on every click and changed nothing.
 *
 * Both vanish once the day is a first-class record. `store_total` / `paged_total` are kept so a
 * failure can EXPLAIN ITSELF ("Shopify says 5,709 sessions, the landing-page breakdown adds up to
 * 5,208") instead of being an opaque amber warning.
 *
 * Additive and non-destructive: production is live (D-002). Existing days are seeded from the rows
 * already in session_traffic_daily, so nothing has to be re-pulled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_traffic_days', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index();   // D-022 tenant seam
            $t->date('date');                       // the BRAND's day

            // The whole point of the table. true = the landing-page breakdown summed EXACTLY to
            // Shopify's own store total for this day, so every read surface may show a number.
            $t->boolean('is_complete')->default(false);

            // Kept so a failure can explain itself rather than just warning.
            $t->unsignedInteger('store_total')->nullable();   // Shopify's own total; null = we never got it
            $t->unsignedInteger('paged_total')->default(0);   // what our landing-page breakdown summed to
            $t->unsignedInteger('rows_written')->default(0);

            $t->timestamp('pulled_at')->nullable();

            $t->unique(['brand_id', 'date'], 'session_traffic_day_unique');
            // The read gate: count complete days for one brand across a window.
            $t->index(['brand_id', 'is_complete', 'date'], 'session_traffic_day_gate');
        });

        /*
         * Seed from what is already synced, so this ships without re-pulling a year of history.
         *
         * MIN(is_complete), not MAX: a day is complete only if EVERY row of it is. The writer
         * stamps one value across the whole day, so they agree — but MIN is the assertion we
         * actually mean, and it fails safe if they ever disagree.
         *
         * Genuinely-zero-session days have no rows and so are NOT seeded here — they simply stay
         * unknown, exactly as they were before, and the repair button will now be able to record
         * them properly (which it previously could not).
         */
        DB::statement(<<<'SQL'
            INSERT INTO session_traffic_days
                (brand_id, workspace_id, date, is_complete, store_total, paged_total, rows_written, pulled_at)
            SELECT
                brand_id,
                MAX(workspace_id),
                date,
                MIN(is_complete),
                NULL,
                SUM(sessions),
                COUNT(*),
                MAX(pulled_at)
            FROM session_traffic_daily
            GROUP BY brand_id, date
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('session_traffic_days');
    }
};
