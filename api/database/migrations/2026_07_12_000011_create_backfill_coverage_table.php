<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day-level backfill coverage — "we have ALREADY pulled (brand, dataset, scope, day)".
 *
 * ══ WHY A LEDGER, AND NOT "does a row exist for that day?" ══
 * Every backfill writes NOTHING for a day the platform returns no data for (`if ($rows === [])
 * continue;`). So a brand that paused its ads for three weeks, or a store with a quiet day, has
 * no row for those days — and is INDISTINGUISHABLE from a day that was never fetched. A
 * presence-based resume would therefore re-pull every genuinely-empty day on every run, forever:
 * the exact days that cost API calls and return nothing.
 *
 * This table records the ATTEMPT, not the outcome. `rows_written = 0` is a first-class, useful
 * fact: "we asked, and the honest answer was nothing." That day is done and is never asked again.
 *
 * ══ WHY NOT JUST USE `--missing` ══
 * `--missing` skips a brand that has ANY rows. A brand interrupted three months into an
 * eighteen-month backfill HAS rows — so `--missing` skips it and those fifteen months are
 * silently lost forever. It is a "skip", not a "resume", and on a job that already died once it
 * is precisely the wrong tool.
 *
 * `scope` is the dataset's sub-axis (platform for ad pulls, dimension for commerce, type for
 * breakdowns). NOT NULL default '' — on MySQL a NULL in a unique index is DISTINCT, so a nullable
 * scope would let the same brand-dataset-day be recorded many times and the resume check would
 * silently stop working. Same trap already hit on budget_plans.country and anomalies.subject.
 *
 * Additive: new table, nothing touched. Existing data stays as it is — the first resumed run
 * simply re-pulls whatever isn't recorded yet, then never again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backfill_coverage', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            // D-022 tenant seam.
            $t->unsignedBigInteger('workspace_id')->nullable()->index();
            $t->string('dataset', 32);                       // sales | commerce | funnel | ad_spend | …
            $t->string('scope', 32)->default('');            // meta | google | product | audience | '' = n/a
            $t->date('date');
            // 0 is MEANINGFUL: "asked, nothing there". It is not a failure and not a gap.
            $t->unsignedInteger('rows_written')->default(0);
            $t->timestamp('completed_at')->nullable();

            $t->unique(['brand_id', 'dataset', 'scope', 'date'], 'backfill_coverage_unique');
            // The resume query: one brand + dataset + scope over a date window.
            $t->index(['brand_id', 'dataset', 'scope', 'date'], 'backfill_coverage_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backfill_coverage');
    }
};
