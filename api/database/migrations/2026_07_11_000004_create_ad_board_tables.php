<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads Library Phase 4 (docs/feature-specs/ads-library.md §Phase 4) — boards +
 * briefs, the "plan ads" workflow. Save any ad (internal winner or market ad) to
 * a board, tag it, and turn a board into a creative brief fed by VERIFIED own
 * data. Every table carries the D-022 `workspace_id` seam at creation.
 *
 * Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_boards', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('workspace_id')->nullable(); // D-022 seam
            $t->string('name', 160);
            $t->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $t->string('niche', 48)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });

        Schema::create('ad_board_items', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('board_id')->constrained('ad_boards')->cascadeOnDelete();
            $t->string('source', 12);          // internal | market
            $t->string('ref_id', 64);          // ad_creative_daily.ad_id OR ad_library_ads.ad_archive_id
            $t->text('note')->nullable();
            $t->json('tags')->nullable();      // ["hook:problem-callout","format:ugc-video",…]
            $t->unsignedInteger('position')->default(0);
            // Boarded INTERNAL ads persist a local thumbnail (CDN urls expire); market
            // ads stay text + permalink (no media stored, per ToS).
            $t->string('thumb_path', 191)->nullable();
            $t->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();

            $t->unique(['board_id', 'source', 'ref_id'], 'ad_board_item_unique');
        });

        Schema::create('ad_briefs', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('workspace_id')->nullable(); // D-022 seam
            $t->foreignId('board_id')->nullable()->constrained('ad_boards')->nullOnDelete();
            $t->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $t->string('title', 200);
            $t->enum('status', ['draft', 'ready', 'shipped'])->default('draft');
            $t->json('blocks')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_briefs');
        Schema::dropIfExists('ad_board_items');
        Schema::dropIfExists('ad_boards');
    }
};
