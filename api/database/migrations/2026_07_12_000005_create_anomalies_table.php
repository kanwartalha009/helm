<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-2.4 (master plan §5.4): the anomaly feed. One row per (brand, date, kind,
 * subject) — deterministic rules only, no LLM. Additive; production is live.
 *
 * The unique key makes the daily scan IDEMPOTENT: re-running it for a day updates the
 * evidence rather than stacking duplicate alerts. An alert feed that duplicates itself
 * is an alert feed people stop reading.
 *
 * `subject` scopes an anomaly to a thing (a product handle, a platform) — '' when the
 * anomaly is about the brand as a whole. NOT NULL with an '' default, because MySQL
 * treats NULLs as distinct and a nullable column would silently break the unique key.
 *
 * Dismissal REQUIRES a reason (enforced in the request, not just the UI). That reason
 * is the honesty record: when GO-3's ledger starts scoring Helm's own suggestions, a
 * dismissal without a stated reason would let the engine quietly bury its misses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam

            $t->date('date');                          // the brand-tz day the rule fired on
            $t->string('kind', 40);                    // cpm_spike | cpa_spike | roas_drop | spend_spike | zero_delivery | stockout_on_ads | mer_divergence
            $t->string('subject', 191)->default('');   // platform / product handle; '' = brand-level
            $t->string('severity', 16);                // info | warn | critical

            // Numbers + the rule + the threshold that fired it. An anomaly a human
            // cannot check by hand is an anomaly a human will not trust.
            $t->json('evidence');

            $t->timestampTz('resolved_at')->nullable();
            $t->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('resolution_reason')->nullable(); // required on dismiss

            $t->timestampsTz();

            $t->unique(['brand_id', 'date', 'kind', 'subject'], 'anomalies_unique');
            $t->index(['brand_id', 'created_at']);     // §9 performance requirement
            $t->index(['brand_id', 'resolved_at']);    // the "open anomalies" query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
