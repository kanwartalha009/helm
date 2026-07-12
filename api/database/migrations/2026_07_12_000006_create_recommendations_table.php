<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-2.5 — THE LEDGER (master plan §5.5, upgrade U2). The compounding moat.
 *
 * Every recommendation Helm makes is logged here: what was suggested, the evidence
 * cited, whether the operator accepted it, and the measured outcome 14/30 days later.
 * The brand page will eventually say "34 suggestions made, 24 accepted, 71% improved
 * the target metric" — computed live from these rows. No competitor does this, and the
 * distrust literature says it is precisely what is missing: unverifiable,
 * incentive-conflicted advice is WHY only ~5% of accounts accept Google's own recs.
 *
 * ══ THE LEDGER IS SACRED (master plan §0, law 2) ══
 * These rows are INSERT-ONLY. The only columns that may ever change after insert are
 * the status columns (open → accepted|dismissed|expired) and the outcome columns
 * (written once, by the measurement job, never by hand). `source`, `kind`, `subject`,
 * `title`, `evidence`, `confidence` and `baseline_value` are FROZEN at creation, and
 * the model throws if anything tries to change them. Deletion throws too.
 *
 * Why so strict: an edited track record is worthless. The entire value of this table is
 * that it cannot be curated after the fact — a bad call from March must still be there
 * in June. A ledger you can quietly tidy is a marketing asset, not an honesty mechanism.
 * Corrections happen as a NEW row pointing at the old one via `supersedes_id`.
 *
 * Ships SILENT in GO-2: writers populate it, nothing renders it. History only starts
 * accruing the day it goes live, which is exactly why it ships before the UI that needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam

            // Which engine produced it, and what it asks the operator to do.
            $t->string('source', 40);   // ad_audit | adset_flags | product_flags | anomaly | seasonal_stale | playbook
            $t->string('kind', 40);     // pause | scale | fix | launch | budget_shift | creative_refresh | investigate

            // What it is ABOUT. subject_id is '' for brand-level advice.
            $t->string('subject_type', 24);          // campaign | adset | ad | product | brand
            $t->string('subject_id', 191)->default('');

            $t->string('title', 255);

            // The numbers, the rule, and the thresholds cited — FROZEN. A recommendation
            // whose evidence can be rewritten later cannot be scored honestly.
            $t->json('evidence');

            $t->string('confidence', 16);            // solid | early (AdAudit's $50/$150 gates)

            // ── the ONLY mutable region ──────────────────────────────────────────────
            $t->string('status', 16)->default('open');   // open | accepted | dismissed | expired
            $t->text('status_reason')->nullable();       // required on dismiss
            $t->foreignId('status_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('status_at')->nullable();

            $t->string('outcome_metric', 24)->nullable();   // roas | cpa | spend_waste | revenue
            $t->decimal('baseline_value', 14, 2)->nullable(); // what the metric was WHEN advised (frozen)
            $t->decimal('measured_value_14d', 14, 2)->nullable();
            $t->decimal('measured_value_30d', 14, 2)->nullable();
            $t->string('outcome', 16)->nullable();          // improved | worsened | flat | unmeasurable
            $t->timestampTz('measured_at')->nullable();
            // ─────────────────────────────────────────────────────────────────────────

            // A correction is a NEW row pointing at the one it replaces. Never an edit.
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->foreign('supersedes_id')->references('id')->on('recommendations')->nullOnDelete();

            $t->timestampsTz();

            $t->index(['brand_id', 'created_at']);   // §9 performance requirement
            // The dedupe lookup: "is there already an OPEN row for this exact subject?"
            $t->index(['brand_id', 'source', 'kind', 'subject_type', 'subject_id', 'status'], 'recommendations_fingerprint_idx');
            $t->index(['status', 'status_at']);      // the measurement job's queue
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
