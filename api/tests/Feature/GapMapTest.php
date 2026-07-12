<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdLibraryAd;
use App\Models\Brand;
use App\Models\User;
use App\Services\AdsLibrary\GapMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-3.4 — the competitor gap map. The join nobody else can make: Proxy competitor
 * presence (public Ad Library) against Verified own spend (our real ad accounts).
 *
 * The tests protect the two claims that could most easily become dishonest:
 *   - the two sides are LABELLED SEPARATELY and never mixed (a competitor's concept
 *     count must never read as spend — the EU Ad Library publishes no competitor spend);
 *   - "we have no country data" is `unknown`, NOT `absent`. Reporting ignorance as
 *     absence would invent a gap that may not exist.
 */
final class GapMapTest extends TestCase
{
    use RefreshDatabase;

    private function brand(?string $niche = 'jewelry'): Brand
    {
        return Brand::factory()->create([
            'base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active', 'niche' => $niche,
        ]);
    }

    /** A competitor ad in the corpus. */
    private function competitorAd(string $id, string $page, array $countries, string $concept, string $media = 'video', string $niche = 'jewelry'): void
    {
        AdLibraryAd::create([
            'ad_archive_id' => $id, 'page_id' => $page, 'page_name' => 'Rival ' . $page,
            'niche' => $niche, 'is_active' => true, 'countries' => $countries,
            'media_type' => $media, 'concept_hash' => $concept, 'delivery_start' => '2026-06-01',
        ]);
    }

    /** Our own country-level spend (Verified). */
    private function ownSpend(Brand $b, string $market, float $usd): void
    {
        DB::table('meta_breakdown_daily')->insert([
            'brand_id' => $b->id, 'date' => now()->subDays(3)->toDateString(),
            'breakdown_type' => 'country', 'segment_key' => $market, 'segment_label' => $market,
            'spend' => $usd, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 1, 'conversion_value' => 100,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function map(Brand $b): array
    {
        return app(GapMap::class)->forBrand($b->fresh());
    }

    public function test_it_finds_a_market_where_competitors_are_live_and_we_are_absent(): void
    {
        $b = $this->brand();

        // 3 rivals, 3 distinct concepts, all live in FR. We spend only in ES.
        $this->competitorAd('A1', 'P1', ['FR'], 'CONCEPT_A');
        $this->competitorAd('A2', 'P2', ['FR'], 'CONCEPT_B');
        $this->competitorAd('A3', 'P3', ['FR'], 'CONCEPT_C');
        $this->competitorAd('B1', 'P1', ['ES'], 'CONCEPT_D');
        $this->ownSpend($b, 'ES', 500);

        $rows = collect($this->map($b)['rows'])->keyBy('market');

        // FR: they're there, we are not. This is the headline card.
        $this->assertSame('absent', $rows['FR']['gap']);
        $this->assertSame(3, $rows['FR']['competitorConcepts']);
        $this->assertSame(3, $rows['FR']['competitorPages']);
        $this->assertEqualsWithDelta(0.0, $rows['FR']['ownSpendUsd'], 0.01);

        // ES: we're present.
        $this->assertSame('present', $rows['ES']['gap']);
        $this->assertEqualsWithDelta(500.0, $rows['ES']['ownSpendUsd'], 0.01);

        // Absent markets sort first — the gap is the point.
        $this->assertSame('FR', $this->map($b)['rows'][0]['market']);
    }

    public function test_variant_spam_is_collapsed_to_concepts(): void
    {
        // One rival running 4 variants of ONE idea is not 4 ideas. Counting raw ads
        // would overstate how busy the market actually is.
        $b = $this->brand();
        foreach (['V1', 'V2', 'V3', 'V4'] as $i => $id) {
            $this->competitorAd($id, 'P1', ['FR'], 'SAME_CONCEPT');
        }

        $fr = collect($this->map($b)['rows'])->firstWhere('market', 'FR');
        $this->assertSame(1, $fr['competitorConcepts']);   // 4 ads, 1 concept
        $this->assertSame(1, $fr['competitorPages']);
    }

    public function test_no_country_data_is_unknown_not_absent(): void
    {
        // We have NO country breakdown at all. Reporting that as "absent" would invent a
        // gap that may not exist — we simply do not know where our money went.
        $b = $this->brand();
        $this->competitorAd('A1', 'P1', ['FR'], 'CONCEPT_A');

        $fr = collect($this->map($b)['rows'])->firstWhere('market', 'FR');

        $this->assertSame('unknown', $fr['gap']);
        $this->assertNull($fr['ownSpendUsd']);   // null, never 0
    }

    public function test_proxy_and_verified_are_labelled_separately_and_never_mixed(): void
    {
        $b = $this->brand();
        $this->competitorAd('A1', 'P1', ['FR'], 'CONCEPT_A');
        $this->ownSpend($b, 'FR', 200);

        $res = $this->map($b);
        $fr  = collect($res['rows'])->firstWhere('market', 'FR');

        // Competitor side: presence only, explicitly no spend.
        $this->assertStringContainsString('Proxy', $fr['proxyLabel']);
        $this->assertStringContainsString('no competitor spend', $fr['proxyLabel']);
        $this->assertArrayNotHasKey('competitorSpend', $fr);   // does not exist and never will

        // Our side: Verified.
        $this->assertStringContainsString('Verified', $fr['verifiedLabel']);

        // And the surface says a gap is a question, not proof.
        $this->assertStringContainsString('not proof that it works', $res['note']);
    }

    public function test_a_brand_with_no_niche_gets_an_honest_refusal(): void
    {
        // Guessing who a brand's competitors are, from its name, is exactly the kind of
        // invention this product does not do.
        $b = $this->brand(niche: null);
        $this->competitorAd('A1', 'P1', ['FR'], 'CONCEPT_A');

        $res = $this->map($b);
        $this->assertSame('no_niche', $res['status']);
        $this->assertSame([], $res['rows']);
    }

    public function test_an_empty_corpus_says_so_rather_than_showing_a_blank_map(): void
    {
        $b = $this->brand();

        $res = $this->map($b);
        $this->assertSame('no_corpus', $res['status']);
        $this->assertStringContainsString('Track competitor pages', $res['note']);
    }

    public function test_endpoint_is_brand_scoped(): void
    {
        $b = $this->brand();
        $this->competitorAd('A1', 'P1', ['FR'], 'CONCEPT_A');
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $res = $this->getJson("/api/brands/{$b->slug}/gap-map")->assertOk()->json();
        $this->assertSame('ok', $res['status']);
        $this->assertSame('jewelry', $res['niche']);
    }
}
