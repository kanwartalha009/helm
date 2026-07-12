<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-2.2 (master plan §5.2): next-month budget PLAN per (brand, month, platform,
 * country). Additive; production is live.
 *
 * This is a PLAN DOCUMENT, not a control surface. Helm never writes budgets to any ad
 * platform (doctrine: "Helm NEVER writes to ad platforms in GO-1..5"). A row here is
 * what a human intends to spend — nothing reads it and pushes it anywhere.
 *
 * `country` is NOT NULL with an '' default meaning "all countries". A nullable column
 * would break the unique key: MySQL treats NULLs as distinct, so two "all countries"
 * rows for the same platform could both be inserted. The empty-string sentinel keeps
 * one plan per (brand, month, platform, country) genuinely unique.
 *
 * v1 plans at PLATFORM level; the country column ships now so per-market planning
 * (GO-4) has its seam and needs no migration on a live table later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_plans', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam

            $t->string('month', 7);                    // 'Y-m', brand-tz calendar month
            $t->string('platform', 16);                // meta | google | tiktok
            $t->string('country', 8)->default('');     // '' = all countries (see docblock)

            $t->decimal('planned_spend', 14, 2);       // native brand currency
            $t->text('note')->nullable();

            $t->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->unique(['brand_id', 'month', 'platform', 'country'], 'budget_plans_unique');
            $t->index(['brand_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_plans');
    }
};
