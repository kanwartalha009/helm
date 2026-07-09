<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `reach` to meta_breakdown_daily so the monthly report's "Ad spend by
 * placement" section (and, later, the audience view) can show reach + frequency
 * (frequency = impressions ÷ reach). Additive + nullable — existing rows keep
 * NULL until re-synced, and every reader treats missing reach as "not captured"
 * rather than 0 (spec rule 9). Reach is not additive across days, so a summed
 * reach is an upper bound and frequency derived from it is approximate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_breakdown_daily', function (Blueprint $table): void {
            $table->unsignedBigInteger('reach')->nullable()->after('clicks');
        });
    }

    public function down(): void
    {
        Schema::table('meta_breakdown_daily', function (Blueprint $table): void {
            $table->dropColumn('reach');
        });
    }
};
