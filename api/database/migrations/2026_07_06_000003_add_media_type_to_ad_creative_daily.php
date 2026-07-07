<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguish image vs video creatives on ad_creative_daily so the Creatives
 * view can badge them and offer video playback. `thumbnail_url` now stores the
 * best available IMAGE (Meta's full image_url / video poster), not the tiny
 * thumbnail. Additive, non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->string('media_type', 16)->nullable()->after('thumbnail_url'); // image | video
        });
    }

    public function down(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->dropColumn('media_type');
        });
    }
};
