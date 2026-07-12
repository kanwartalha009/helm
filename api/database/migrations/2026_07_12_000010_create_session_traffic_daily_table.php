<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions by traffic type, per landing entity, per day (Bosco item B — probe verdict B1).
 *
 * Source: ShopifyQL `FROM sessions ... GROUP BY landing_page_path, traffic_type`. The probe
 * (2026-07-12, Flabelus) confirmed the two dimensions COMBINE, and combine with `day`.
 *
 * ══ WHY THIS IS NOT KEYED ON RAW `landing_path`, AS SPEC §B.2 ASKS ══
 * The spec's table is (brand, date, landing_path, traffic_type). Measured against a real
 * store, a SINGLE DAY of one brand yields between 2,501 and 5,000 distinct
 * (landing_path × traffic_type) rows — and the tail is garbage. The row at OFFSET 2500 was:
 *
 *     ["/checkouts/cn/hWNEHT1fJycojLAkIESuSzUh/en-gb", "direct", 1]
 *
 * i.e. a ONE-TIME checkout token. Every checkout session mints a unique URL, so raw
 * landing_path has effectively UNBOUNDED cardinality. Keying on it would write ~3.5k rows per
 * brand per day (≈100M rows/year across ~80 live brands), almost all of them single-session
 * URLs that will never be read, on a live MySQL box. That is a table that eats the server to
 * store noise.
 *
 * So the landing path is RESOLVED TO ITS ENTITY AT SYNC TIME (LandingPathMapper) and the rows
 * are aggregated:
 *
 *     entity_type = 'product'    → entity_key = product handle   (locale prefixes collapse:
 *                                  /es/products/jay + /fr/products/jay + /products/jay = jay)
 *     entity_type = 'collection' → entity_key = collection handle
 *     entity_type = 'other'      → entity_key = 'store-wide'     (home, /pages, /blogs, search,
 *                                  checkout tokens, junk — ONE row per traffic type per day)
 *
 * The whole unmapped tail therefore folds into four 'other' rows a day instead of thousands.
 * Nothing is DROPPED — §B.3 requires the totals to still reconcile, and they do: Σ(all rows for
 * a day) = the store's session total for that day. That equality is what `is_complete` asserts.
 *
 * Cardinality after mapping: (products with traffic + collections + 1) × traffic types present.
 *
 * `is_complete = false` means the paged sum did NOT reconcile against Shopify's own store total
 * for that day — the row set is short, and every read surface must render "—", never a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_traffic_daily', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            // D-022 tenant seam. Nullable at creation, populated when tenancy lands.
            $t->unsignedBigInteger('workspace_id')->nullable()->index();
            $t->date('date');                       // the BRAND's day (ShopifyQL groups in store tz)
            $t->string('entity_type', 16);          // product | collection | other
            $t->string('entity_key', 191);          // handle, or 'store-wide' for other
            $t->string('traffic_type', 24);         // paid | direct | organic | unknown (Shopify's own)
            $t->unsignedInteger('sessions')->default(0);
            // false = the day's rows did not reconcile to the store total → render "—", not a number.
            $t->boolean('is_complete')->default(true);
            $t->timestamp('pulled_at')->nullable();

            $t->unique(
                ['brand_id', 'date', 'entity_type', 'entity_key', 'traffic_type'],
                'session_traffic_unique',
            );
            // The read path: one brand, one entity type, a date window.
            $t->index(['brand_id', 'entity_type', 'date'], 'session_traffic_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_traffic_daily');
    }
};
