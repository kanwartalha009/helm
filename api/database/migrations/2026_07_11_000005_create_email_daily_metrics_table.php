<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-1.1 (growth-os-master-plan §4.1): Klaviyo-attributed email revenue per brand,
 * per day, per source (flow | campaign). One row per (brand, date, source, source_id).
 * Additive; production is live.
 *
 * Honesty law (master plan §0.1 + §3.1): Klaviyo revenue is LAST-TOUCH within
 * Klaviyo's own attribution windows — it OVERLAPS ad-platform and organic revenue
 * and must NEVER be summed into a "total attributed" figure. It renders as its own
 * channel column with the attribution honesty box. Values are raw store currency
 * (Klaviyo does no conversion) → we snapshot brand currency + fx_rate_to_usd like
 * every other metric table, and do USD ratio math at read time.
 *
 * Missing ≠ zero: a day never synced has NO row (renders "—"), never a 0. A failed
 * or partial pull leaves is_complete=false. workspace_id is the D-022 tenant seam
 * (nullable, no behaviour today).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_daily_metrics', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable()->index(); // D-022 seam (no FK yet)
            $t->date('date');                          // brand timezone

            $t->string('source', 16);                  // 'flow' | 'campaign'
            $t->string('source_id', 64);               // Klaviyo flow/campaign id ($attributed_flow/$attributed_message)
            $t->string('source_name', 255)->nullable();

            $t->unsignedInteger('conversions')->default(0);       // Placed Order count
            $t->decimal('conversion_value', 14, 2)->default(0);   // Placed Order sum_value, native currency
            $t->string('currency', 8)->nullable();
            $t->decimal('fx_rate_to_usd', 14, 8)->nullable();
            $t->boolean('is_complete')->default(true);
            $t->timestampTz('pulled_at')->nullable();
            $t->timestampsTz();

            $t->unique(['brand_id', 'date', 'source', 'source_id'], 'email_daily_unique');
            $t->index(['brand_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_daily_metrics');
    }
};
