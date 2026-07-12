<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-2.1 (master plan §5.1): monthly targets per brand — the number the month is
 * actually being run against. Pacing compares actuals to these. Additive; prod is live.
 *
 * `month` is a 'Y-m' STRING, not a date: a target belongs to a calendar month in the
 * BRAND's timezone, and storing a date would invite timezone drift at the month
 * boundary (the exact class of bug guardrail 8 exists to prevent).
 *
 * Every target is NULLABLE and independent. A brand may set only a revenue target and
 * nothing else — an unset target is unset, never 0, and pacing simply doesn't render
 * for it. Helm never invents a number the operator didn't give it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_targets', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)

            $t->string('month', 7);                              // 'Y-m', brand-tz calendar month

            $t->decimal('revenue_target', 14, 2)->nullable();    // native brand currency
            $t->decimal('spend_cap', 14, 2)->nullable();
            $t->decimal('roas_target', 8, 2)->nullable();        // platform-attributed ROAS
            $t->decimal('mer_target', 8, 2)->nullable();         // store-truth MER (GO-1.4 spine)

            $t->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->unique(['brand_id', 'month'], 'brand_targets_unique');
            $t->index(['brand_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_targets');
    }
};
