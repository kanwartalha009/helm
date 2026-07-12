<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads Library Phase 1 (docs/feature-specs/ads-library.md §Phase 1.1).
 *
 * `ad_creative_daily` stores ad_name + thumbnail_url + media_type but NO creative
 * text, so hook/copy search over our OWN winners is impossible today. Add
 * `body_text` (the primary creative body). Populated from the own-account
 * Marketing API by the Meta/TikTok creative fetchers — zero Ad Library ToS
 * exposure (this is the agency's own ad data). Existing rows stay NULL → render
 * "—" until the next creatives sync/backfill. Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->text('body_text')->nullable()->after('ad_name');
        });
    }

    public function down(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->dropColumn('body_text');
        });
    }
};
