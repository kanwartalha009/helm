<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 of the product-audit / underperformer build
 * (docs/feature-specs/product-audit-adset-underperformers.md §4 Phase 0).
 *
 * Two nullable per-brand inputs the agency fills in Settings:
 *  - gross_margin_pct — what's left of revenue after product cost (%). Drives the
 *    breakeven ROAS = 1 ÷ (margin/100) [SOURCED Triple Whale, algebraic].
 *  - target_cpa — the brand's target cost per acquisition (native currency).
 *
 * Both NULLABLE on purpose: margin-based and kill-by-CPA rules stay silently OFF
 * until set — never guessed (guardrail 3, missing ≠ zero). Additive only;
 * production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $t): void {
            $t->decimal('gross_margin_pct', 5, 2)->nullable()->after('base_currency');
            $t->decimal('target_cpa', 12, 2)->nullable()->after('gross_margin_pct'); // native currency
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $t): void {
            $t->dropColumn(['gross_margin_pct', 'target_cpa']);
        });
    }
};
