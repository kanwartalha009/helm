<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 of the Ads Library build (docs/feature-specs/ads-library.md §Phase 0).
 *
 * Per-brand `niche` (e.g. fashion, footwear, jewelry, skincare, backpacks) — the
 * axis both the internal winners library and the market/competitor library filter
 * and rank within (percentiles are computed niche × country × window so scores
 * compare like with like).
 *
 * NULLABLE on purpose: an unassigned brand appears under "Unassigned" and is never
 * guessed (guardrail: missing ≠ zero). The agency sets it in the brand Settings
 * tab. Additive only; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $t): void {
            $t->string('niche', 48)->nullable()->after('target_cpa');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $t): void {
            $t->dropColumn('niche');
        });
    }
};
