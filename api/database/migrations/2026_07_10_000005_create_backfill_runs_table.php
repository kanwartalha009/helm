<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per operator-triggered dataset backfill (campaigns / creatives /
 * commerce) so the brand page can show queued/running/done/failed without
 * guessing. The daily-metrics history backfill is NOT tracked here — it fans
 * out through sync_logs (BackfillBrandRangeJob) and Sync health owns that
 * visibility. Additive migration — production is live.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('backfill_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('dataset', 24);              // campaigns | creatives | commerce
            $t->string('status', 16)->default('queued'); // queued | running | done | failed
            $t->date('window_start');               // how far back this run pulls
            $t->text('message')->nullable();        // command output tail / error
            $t->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();

            $t->index(['brand_id', 'dataset', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backfill_runs');
    }
};
