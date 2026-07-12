<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GO-4.1 (master plan §7.1): the EU market calendar — when each market actually shops.
 *
 * This is the foundation of the seasonal playbook engine (the market whitespace: nobody
 * sells a per-market, per-season plan grounded in the customer's own data).
 *
 * Why a TABLE and not a config array: these dates MOVE. Soldes are fixed by French law
 * to the 2nd Wednesday of January — a different calendar date every year. Mother's Day
 * is a different Sunday in every market, and in France it shifts again when it collides
 * with Pentecost. A hardcoded date is a wrong number waiting to happen, so the dates are
 * COMPUTED per year and seeded (`calendar:seed {year}`), with `source` on every row so a
 * human can check where the claim came from.
 *
 * `kind`:
 *   legal_sale — fixed by law (FR soldes, BE solden, IT/ES regional statutes). You cannot
 *                discount outside these windows in some markets; the date is not advice.
 *   gift       — a gift-giving moment (Three Kings, Sinterklaas, Mother's Day).
 *   event      — a commercial moment with no legal force (BFCM, Singles Day, back-to-school).
 *
 * The unique key is (market, moment_key, year), so re-seeding a year updates rather than
 * duplicating. Additive; production is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_moments', function (Blueprint $t): void {
            $t->bigIncrements('id');

            $t->char('market', 2);                  // ISO-3166 alpha-2: FR, ES, IT, DE, AT, BE, NL, PL
            $t->string('moment_key', 48);           // soldes_hiver | rebajas_invierno | three_kings | black_friday …
            $t->string('label', 120);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('kind', 16);                 // legal_sale | gift | event
            // WHERE THE CLAIM COMES FROM. A calendar entry a human cannot check is a
            // calendar entry a human should not plan a client's quarter around.
            $t->string('source', 191);
            $t->unsignedSmallInteger('year');

            $t->timestampsTz();

            $t->unique(['market', 'moment_key', 'year'], 'market_moments_unique');
            $t->index(['market', 'starts_on']);
            $t->index(['year', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_moments');
    }
};
