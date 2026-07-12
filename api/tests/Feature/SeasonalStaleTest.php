<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Recommendation;
use App\Services\Ledger\LedgerRecorder;
use App\Services\Rules\SeasonalStale;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GO-3.1 — the seasonal-stale creative detector (the flagship rule).
 *
 * Two properties are load-bearing and both are tested at their boundary:
 *   1. It fires when a live ad is still spending on a dead hook.
 *   2. It stays SILENT in-season and during the grace period. A detector that shouts
 *      at an ad winding down over a normal few days is a detector nobody reads.
 *
 * And the invariant that protects the whole product: the trigger is a keyword+date
 * RULE. No LLM is involved, and the test asserts the detector works identically with
 * no LLM key configured at all.
 */
final class SeasonalStaleTest extends TestCase
{
    use RefreshDatabase;

    private function brand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => 'UTC', 'status' => 'active']);
    }

    /** A live ad: it spent yesterday, so it is costing money right now. */
    private function liveAd(Brand $b, string $adId, string $name, ?string $body, string $onDate, float $spend = 100): void
    {
        DB::table('ad_creative_daily')->insert([
            'brand_id' => $b->id, 'platform' => 'meta', 'date' => $onDate,
            'ad_id' => $adId, 'ad_name' => $name, 'body_text' => $body, 'media_type' => 'image',
            'spend' => $spend, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 1, 'conversion_value' => 50,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function detect(Brand $b, string $today): array
    {
        return app(SeasonalStale::class)->forBrand($b->fresh(), CarbonImmutable::parse($today, 'UTC'));
    }

    public function test_the_flagship_case_christmas_copy_still_live_in_february(): void
    {
        // The master plan's stated proof: a Christmas-copy ad past Jan 6 + grace.
        $b = $this->brand();
        $this->liveAd($b, 'AD1', 'Navidad 2025 — regalos', 'Los mejores regalos de Navidad. Reyes Magos incluidos.', '2026-02-09', 250);

        $hits = $this->detect($b, '2026-02-10');

        $this->assertCount(1, $hits);
        $h = $hits[0];
        $this->assertSame('christmas', $h['season']);
        $this->assertSame('2026-01-06', $h['seasonEnded']);        // through Three Kings (ES/IT)
        $this->assertSame('2026-01-13', $h['staleSince']);          // + 7 days grace
        $this->assertSame(28, $h['daysStale']);
        $this->assertEqualsWithDelta(250.0, $h['spend'], 0.01);     // real money, still burning
        // The evidence names exactly what fired it — checkable in two seconds.
        $this->assertContains('navidad', $h['matchedTerms']);
        $this->assertContains('reyes magos', $h['matchedTerms']);
    }

    public function test_silent_in_season(): void
    {
        // Christmas copy on 20 December is not a mistake. It is Christmas.
        $b = $this->brand();
        $this->liveAd($b, 'AD1', 'Weihnachtsgeschenke', 'Die besten Geschenke zu Weihnachten.', '2025-12-19');

        $this->assertSame([], $this->detect($b, '2025-12-20'));
    }

    public function test_silent_inside_the_grace_period_and_loud_just_after(): void
    {
        // Christmas ends Jan 6; grace is 7 days → stale from Jan 13.
        $b = $this->brand();
        $this->liveAd($b, 'AD1', 'Noël', 'Cadeaux de Noël.', '2026-01-12');

        // Jan 13 = exactly the grace boundary → still silent (winding down is normal).
        $this->assertSame([], $this->detect($b, '2026-01-13'));

        // Jan 14 = one day past → fires.
        $hits = $this->detect($b, '2026-01-14');
        $this->assertCount(1, $hits);
        $this->assertSame('christmas', $hits[0]['season']);
        $this->assertContains('noël', $hits[0]['matchedTerms']);   // accent-insensitive match
    }

    public function test_matches_across_languages(): void
    {
        $b = $this->brand();
        // FR winter sales (ends Feb 28, +7 grace → stale from Mar 7).
        $this->liveAd($b, 'FR1', "Soldes d'hiver", "Profitez des soldes d'hiver !", '2026-04-01');
        // IT summer sales (ends Aug 31) — not stale in April (its last end was Aug 2025,
        // long past, so it IS stale). Use an unambiguous in-window case instead below.
        $this->liveAd($b, 'NL1', 'Kerstcadeau', 'Het beste kerstcadeau.', '2026-04-01');

        $hits = collect($this->detect($b, '2026-04-02'))->keyBy('adId');

        $this->assertSame('winter_sale', $hits['FR1']['season']);
        $this->assertSame('christmas', $hits['NL1']['season']);
        $this->assertContains('kerstcadeau', $hits['NL1']['matchedTerms']);
    }

    public function test_an_ad_with_no_spend_is_not_flagged(): void
    {
        // Nothing to save on an ad that isn't running. Missing ≠ a problem.
        $b = $this->brand();
        $this->liveAd($b, 'AD1', 'Navidad', 'Regalos de Navidad', '2026-02-09', spend: 0);

        $this->assertSame([], $this->detect($b, '2026-02-10'));
    }

    public function test_an_ad_with_no_seasonal_words_is_not_flagged(): void
    {
        // The detector is deliberately specific. Generic copy must never fire it — a
        // badge that appears on everything is a badge nobody reads.
        $b = $this->brand();
        $this->liveAd($b, 'AD1', 'Everyday running shoes', 'Free shipping on all orders.', '2026-02-09');

        $this->assertSame([], $this->detect($b, '2026-02-10'));
    }

    public function test_no_llm_is_involved_in_the_trigger(): void
    {
        // THE INVARIANT (D-016 / §6.1): an LLM may enrich prose, but it can never be the
        // reason an alert exists. With no LLM key configured at all, the detector must
        // behave identically — because it never asks a model anything.
        config()->set('llm.provider', 'anthropic');
        // (no credential rows exist in this test DB → LlmManager::enabled() is false)

        $b = $this->brand();
        $this->liveAd($b, 'AD1', 'Black Friday deals', 'Black Friday: 50% off.', '2026-02-09');

        $hits = $this->detect($b, '2026-02-10');
        $this->assertCount(1, $hits);
        $this->assertSame('black_friday', $hits[0]['season']);
    }

    public function test_it_writes_a_creative_refresh_recommendation_to_the_ledger(): void
    {
        $b = $this->brand();
        $this->liveAd($b, 'AD1', 'Navidad 2025', 'Regalos de Navidad.', '2026-02-09', 300);

        app(LedgerRecorder::class)->recordForBrand($b->fresh(), CarbonImmutable::parse('2026-02-10', 'UTC'));

        $r = Recommendation::where('brand_id', $b->id)->where('source', 'seasonal_stale')->firstOrFail();

        $this->assertSame('creative_refresh', $r->kind);
        $this->assertSame('ad', $r->subject_type);
        $this->assertSame('AD1', $r->subject_id);
        $this->assertSame('spend_waste', $r->outcome_metric);
        $this->assertSame('christmas', $r->evidence['season']);
        $this->assertContains('navidad', $r->evidence['matchedTerms']);
        // The evidence states, in the row itself, that no model was involved.
        $this->assertStringContainsString('no model', $r->evidence['trigger']);
    }
}
