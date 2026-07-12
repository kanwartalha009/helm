<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads Library Phase 2 (docs/feature-specs/ads-library.md §Phase 2.2) — the market
 * library storage. EU Ad Library archives commercial ads for only ~1 year, so
 * Helm keeps its own TEXT + metadata copy of tracked ads (NO media files — ToS:
 * batch download not permitted; ad_snapshot_url embeds the token and is NEVER
 * stored — only the public permalink).
 *
 * Product lens (D-022): every table that scopes to an agency carries a nullable
 * `workspace_id` seam AT CREATION (retrofitting tenancy onto a populated table is
 * a migration project; a nullable column now costs nothing). `ad_library_ads`
 * stays GLOBAL-keyed on ad_archive_id — it's public data, shareable across
 * tenants; only the tracked-page / saved-search LINKS are per-workspace.
 * Cross-tenant pooling of the ranked corpus is still forbidden without a ratified
 * opt-in (enforced at query time, not here).
 *
 * Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tracked competitor pages (per-workspace).
        Schema::create('ad_library_pages', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('workspace_id')->nullable(); // D-022 tenancy seam (no behavior today)
            $t->string('page_id', 40);
            $t->string('page_name', 255)->nullable();
            $t->string('niche', 48)->nullable();
            $t->string('country_default', 8)->nullable();
            $t->string('status', 16)->default('active'); // active | paused
            $t->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('last_refreshed_at')->nullable();
            $t->timestampsTz();

            $t->unique(['workspace_id', 'page_id'], 'ad_library_pages_unique');
            $t->index('niche');
        });

        // Saved niche/keyword searches (per-workspace), refreshed on a schedule.
        Schema::create('ad_library_searches', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignId('workspace_id')->nullable(); // D-022 tenancy seam
            $t->string('label', 120);
            $t->string('terms', 100);                  // API hard cap: 100 chars
            $t->string('search_type', 24)->default('KEYWORD_UNORDERED');
            $t->json('countries')->nullable();
            $t->json('filters')->nullable();
            $t->string('niche', 48)->nullable();
            $t->enum('schedule', ['nightly', 'weekly', 'off'])->default('weekly');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('last_run_at')->nullable();
            $t->timestampsTz();

            $t->index('niche');
        });

        // The archived-ad corpus — GLOBAL (public data), keyed on ad_archive_id.
        Schema::create('ad_library_ads', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('ad_archive_id', 32)->unique();
            $t->string('page_id', 40)->index();
            $t->string('page_name', 255)->nullable();
            // Denormalised from the tracked page / search that surfaced this ad, so
            // the corpus percentiles compare within (niche × country × window).
            $t->string('niche', 48)->nullable()->index();
            $t->json('countries')->nullable();
            $t->string('permalink', 255)->nullable(); // facebook.com/ads/library/?id=… (token-free)
            $t->timestampTz('ad_created_at')->nullable();
            $t->date('delivery_start')->nullable()->index();
            $t->date('delivery_stop')->nullable();
            $t->boolean('is_active')->default(true)->index(); // derived from the last sweep
            // Creative TEXT only (no media files, per ToS).
            $t->json('creative_bodies')->nullable();
            $t->json('link_titles')->nullable();
            $t->json('link_captions')->nullable();
            $t->json('link_descriptions')->nullable();
            $t->json('languages')->nullable();
            $t->json('platforms')->nullable();
            // The ArchivedAd node has NO media_type — derived from the SEARCH filter
            // used (ALL/IMAGE/VIDEO sweep), else null → "—", never a guess.
            $t->string('media_type', 8)->nullable();
            // EU-disclosed reach (null when absent — never 0).
            $t->unsignedBigInteger('eu_total_reach')->nullable();
            $t->json('reach_breakdown')->nullable();
            $t->json('target_ages')->nullable();
            $t->string('target_gender', 8)->nullable();
            $t->json('target_locations')->nullable();
            $t->json('beneficiary_payers')->nullable();
            // sha1(page_id + normalized first 120 chars of first body) — fallback
            // chain body → first link_title → ad_archive_id so textless ads never
            // collapse into one false mega-concept.
            $t->string('concept_hash', 40)->index();
            // Score materialization (Phase 2.6) — computed nightly, sorted on-index.
            $t->unsignedInteger('longevity_days')->nullable();
            $t->decimal('signal_score', 6, 4)->nullable()->index();
            $t->timestampTz('first_seen_at')->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->json('raw')->nullable();
            $t->timestampsTz();

            $t->index(['niche', 'delivery_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_library_ads');
        Schema::dropIfExists('ad_library_searches');
        Schema::dropIfExists('ad_library_pages');
    }
};
