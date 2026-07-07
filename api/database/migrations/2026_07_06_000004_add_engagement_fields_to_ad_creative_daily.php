<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creative engagement fields for the Creatives view's video/image quality
 * metrics: 3-second video views (Thumbstop), ThruPlays (Hold rate) and
 * add-to-cart (Click→ATC). All piggyback the existing ad-level insights pull,
 * so no extra Meta calls. Additive, non-destructive, default 0 so historical
 * rows read as "no engagement recorded" rather than null-math surprises.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->unsignedBigInteger('video_3s')->default(0)->after('clicks');    // 3-sec video views → Thumbstop
            $t->unsignedBigInteger('thruplays')->default(0)->after('video_3s'); // ThruPlays → Hold rate
            $t->unsignedBigInteger('add_to_cart')->default(0)->after('thruplays'); // → Click-to-ATC
        });
    }

    public function down(): void
    {
        Schema::table('ad_creative_daily', function (Blueprint $t): void {
            $t->dropColumn(['video_3s', 'thruplays', 'add_to_cart']);
        });
    }
};
