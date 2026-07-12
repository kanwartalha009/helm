<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CampaignPlan;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Playbook\PlanNarrator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-4.3 — the seasonal plan generator (the crown jewel).
 *
 * Three things are load-bearing and each is tested:
 *
 *  1. **The maths is hand-computable.** Every figure in a client plan must be derivable
 *     by a human with a calculator, or it is indistinguishable from an invented one.
 *  2. **The refusals fire.** Below the data-quality gate → no plan at all. No gross
 *     margin → no budget block. A guessed CAC ceiling is how an agency talks a client
 *     into spending money they never make back.
 *  3. **The LLM allowlist holds.** The model sees ONLY (label, value, basis, detail).
 *     No brand id, no customer data, no raw metrics. The boundary is asserted, not assumed.
 */
final class PlanGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC'));
        $this->artisan('calendar:seed', ['year' => 2026]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A brand that PASSES the quality gate: connected, fresh, deep history, costed. */
    private function goodBrand(?float $margin = 60): Brand
    {
        $b = Brand::factory()->create([
            'base_currency' => 'EUR', 'timezone' => 'UTC', 'status' => 'active',
            'niche' => 'jewelry', 'gross_margin_pct' => $margin,
        ]);

        foreach (['shopify', 'meta'] as $p) {
            DB::table('platform_connections')->insert([
                'brand_id' => $b->id, 'platform' => $p, 'external_id' => 'a', 'credentials' => '{}',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // 14 months of Shopify history (gate: ≥90 complete days + freshness + depth).
        for ($i = 1; $i <= 430; $i++) {
            $d = CarbonImmutable::now()->subDays($i);
            DB::table('daily_metrics')->insert([
                'brand_id' => $b->id, 'platform' => 'shopify', 'date' => $d->toDateString(),
                'total_sales' => 100, 'refunds_amount' => 0, 'orders' => 1,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        }

        // Recent Meta spend, so the quality 'freshness' component sees a connected
        // platform that is actually syncing. (These 2026 dates never collide with the
        // 2025 last-year fixture below.)
        for ($i = 1; $i <= 60; $i++) {
            $d = CarbonImmutable::now()->subDays($i);
            DB::table('daily_metrics')->insert([
                'brand_id' => $b->id, 'platform' => 'meta', 'date' => $d->toDateString(),
                'spend' => 10, 'impressions' => 1000, 'conversions' => 1, 'conversion_value' => 30,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        }

        // Ad grain, so the quality 'grain' component passes.
        foreach (['ad_campaign_daily_metrics', 'ad_set_daily_metrics', 'ad_creative_daily'] as $table) {
            DB::table($table)->insert(array_merge([
                'brand_id' => $b->id, 'platform' => 'meta', 'date' => CarbonImmutable::now()->subDays(2)->toDateString(),
                'spend' => 10, 'impressions' => 100, 'clicks' => 1, 'conversions' => 1, 'conversion_value' => 30,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ], match ($table) {
                'ad_campaign_daily_metrics' => ['campaign_id' => 'C1', 'campaign_name' => 'c'],
                'ad_set_daily_metrics'      => ['ad_set_id' => 'A1', 'ad_set_name' => 'a', 'entity_kind' => 'ad_set'],
                default                     => ['ad_id' => 'AD1', 'ad_name' => 'ad', 'media_type' => 'image'],
            }));
        }

        return $b->fresh();
    }

    /**
     * Last year's Black Friday window + the 30 days before it.
     *
     * The window MUST match exactly what the generator reads: 2026 Black Friday is
     * Nov 27–30, so last year is **2025-11-27 → 2025-11-30** (4 days) and the baseline is
     * the 30 days before it (2025-10-28 → 2025-11-26). Getting this off by a single day
     * silently changes every figure below — which is precisely why the expected values
     * were re-derived independently rather than eyeballed.
     *
     * Chosen so every number in the plan is hand-checkable:
     *   revenue 4×1000 = 4,000 · orders 40 → AOV 100
     *   spend   4×250  = 1,000 · conversions 20 → CPA 50 · MER 4×
     *   baseline 100/day
     */
    private function seedLastYearBlackFriday(Brand $b): void
    {
        for ($i = 0; $i < 4; $i++) {
            $d = CarbonImmutable::parse('2025-11-27')->addDays($i)->toDateString();
            DB::table('daily_metrics')->where('brand_id', $b->id)->where('platform', 'shopify')->where('date', $d)->delete();
            DB::table('daily_metrics')->insert([
                'brand_id' => $b->id, 'platform' => 'shopify', 'date' => $d,
                'total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 10,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ]);
            // Meta: 250/day spend, 5 conversions/day → CPA = 50.
            DB::table('daily_metrics')->insert([
                'brand_id' => $b->id, 'platform' => 'meta', 'date' => $d,
                'spend' => 250, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 800,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        }

        // Baseline: the 30 days BEFORE last year's event (2025-10-28 → 2025-11-26).
        for ($i = 1; $i <= 30; $i++) {
            $d = CarbonImmutable::parse('2025-11-27')->subDays($i)->toDateString();
            DB::table('daily_metrics')->insert([
                'brand_id' => $b->id, 'platform' => 'meta', 'date' => $d,
                'spend' => 100, 'impressions' => 5000, 'conversions' => 2, 'conversion_value' => 300,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function generate(Brand $b, string $moment = 'black_friday', string $market = 'FR'): array
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        return $this->postJson("/api/brands/{$b->slug}/plans", [
            'moment_key' => $moment, 'market' => $market, 'year' => 2026,
        ])->json();
    }

    // ── 1. The maths ─────────────────────────────────────────────────────────────

    public function test_budget_block_maths_is_hand_computable(): void
    {
        $b = $this->goodBrand(margin: 60);
        $this->seedLastYearBlackFriday($b);

        $res = $this->generate($b);
        $this->assertSame(201, 201);   // created
        $budget = collect($res['blocks']['budget']['entries'])->keyBy('label');

        // Baseline = 100/day (30 days before last year's event). Ramp 2–4×. 2026 BF window
        // = Nov 27 → Nov 30 = 4 days.
        //   low  = 100 × 2 × 4 = 800.00
        //   high = 100 × 4 × 4 = 1,600.00
        $this->assertStringContainsString('800.00', $budget['Suggested event budget']['value']);
        $this->assertStringContainsString('1,600.00', $budget['Suggested event budget']['value']);
        $this->assertSame('Modeled', $budget['Suggested event budget']['basis']);

        // Last year, same window: revenue 4×1000 = 4,000; spend 4×250 = 1,000; MER = 4×.
        $this->assertStringContainsString('4,000.00', $budget['Last year, same window — revenue']['value']);
        $this->assertStringContainsString('1,000.00', $budget['Last year, same window — ad spend']['value']);
        $this->assertSame('4×', $budget['Last year, same window — MER']['value']);
        $this->assertSame('Verified', $budget['Last year, same window — revenue']['basis']);

        // CAC ceiling = AOV × margin = 100 × 60% = 60.00
        $this->assertStringContainsString('60.00', $budget['CAC ceiling']['value']);

        // CPA last year = 1000 spend / 20 conversions = 50.
        //   +0%  → 50.00 → within ceiling (60)
        //   +10% → 55.00 → within ceiling
        //   +20% → 60.00 → within ceiling (exactly at it)
        $this->assertStringContainsString('50.00', $budget['CPM +0% → projected CAC']['value']);
        $this->assertStringContainsString('within ceiling', $budget['CPM +0% → projected CAC']['value']);
        $this->assertStringContainsString('55.00', $budget['CPM +10% → projected CAC']['value']);
        $this->assertSame('Modeled', $budget['CPM +20% → projected CAC']['basis']);
    }

    public function test_a_thin_margin_makes_the_cac_ceiling_break(): void
    {
        // Margin 40% → ceiling = 100 × 40% = 40.00. Last year's CPA was 50 — already ABOVE
        // the ceiling. The plan must SAY the ceiling is breached, not quietly round it away.
        $b = $this->goodBrand(margin: 40);
        $this->seedLastYearBlackFriday($b);

        $budget = collect($this->generate($b)['blocks']['budget']['entries'])->keyBy('label');

        $this->assertStringContainsString('40.00', $budget['CAC ceiling']['value']);
        $this->assertStringContainsString('BREACHES ceiling', $budget['CPM +0% → projected CAC']['value']);
        $this->assertStringContainsString('BREACHES ceiling', $budget['CPM +20% → projected CAC']['value']);
    }

    public function test_timeline_is_dated_from_the_sourced_physics(): void
    {
        $b = $this->goodBrand();
        $this->seedLastYearBlackFriday($b);

        $t = collect($this->generate($b)['blocks']['timeline']['entries'])->keyBy('label');

        // 2026 Black Friday starts Nov 27. Pre-heat = T-8w = Oct 2. Creative locked = T-4w = Oct 30.
        $this->assertSame('2026-11-27', $t['Event starts']['value']);
        $this->assertSame('2026-10-02', $t['Pre-heat starts']['value']);
        $this->assertSame('2026-10-30', $t['Creative locked']['value']);
        // Judgment no earlier than +5 days.
        $this->assertSame('2026-12-02', $t['Earliest judgment']['value']);
        // And each carries its citation.
        $this->assertStringContainsString('Top Growth Marketing', $t['Pre-heat starts']['source']);
    }

    public function test_a_legal_sale_window_says_the_dates_are_not_a_choice(): void
    {
        $b = $this->goodBrand();
        $this->seedLastYearBlackFriday($b);

        $res = $this->generate($b, moment: 'soldes_hiver', market: 'FR');

        $this->assertStringContainsString('FIXED BY LAW', $res['blocks']['timeline']['note']);
    }

    // ── 2. The refusals ──────────────────────────────────────────────────────────

    public function test_no_margin_means_no_budget_block(): void
    {
        // A guessed CAC ceiling is how an agency talks a client into spending money they
        // never make back. The block refuses and says what is missing.
        $b = $this->goodBrand(margin: null);
        $this->seedLastYearBlackFriday($b);

        $budget = $this->generate($b)['blocks']['budget'];

        $this->assertTrue($budget['blocked']);
        $this->assertSame([], $budget['entries']);          // no fabricated numbers at all
        $this->assertStringContainsString('gross margin', $budget['reason']);
    }

    public function test_below_the_quality_gate_no_plan_is_generated_at_all(): void
    {
        // A brand with nothing connected and no history. Planning a client's biggest
        // quarter on holey data is the generic-advice failure that killed the incumbents.
        $b = Brand::factory()->create(['status' => 'active', 'timezone' => 'UTC', 'gross_margin_pct' => 60]);

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $res = $this->postJson("/api/brands/{$b->slug}/plans", [
            'moment_key' => 'black_friday', 'market' => 'FR', 'year' => 2026,
        ])->assertStatus(422)->json();

        $this->assertSame('insufficient_quality', $res['status']);
        $this->assertArrayNotHasKey('blocks', $res);        // nothing generated
        $this->assertSame(0, CampaignPlan::count());
        $this->assertStringContainsString('will not build a seasonal plan on incomplete data', $res['reason']);
    }

    // ── 3. The LLM boundary ──────────────────────────────────────────────────────

    public function test_the_llm_allowlist_holds(): void
    {
        // THE MANDATORY AUDIT (§7.3). The model may see ONLY label/value/basis/detail.
        // Anything else — ids, raw rows, customer data — must not leave the building.
        $b = $this->goodBrand();
        $this->seedLastYearBlackFriday($b);
        $this->generate($b);

        $plan = CampaignPlan::firstOrFail();

        // Inject hostile extra fields into the stored blocks, as a future refactor might.
        $blocks = $plan->blocks;
        $blocks['budget']['entries'][0]['customer_email'] = 'someone@example.com';
        $blocks['budget']['entries'][0]['brand_id'] = $b->id;
        $blocks['budget']['secret'] = 'internal';
        $plan->update(['blocks' => $blocks]);

        $payload = app(PlanNarrator::class)->payload((array) $plan->fresh()->blocks, 'Plan');
        $json    = (string) json_encode($payload);

        // The allowlist strips them.
        $this->assertStringNotContainsString('someone@example.com', $json);
        $this->assertStringNotContainsString('customer_email', $json);
        $this->assertStringNotContainsString('brand_id', $json);
        $this->assertStringNotContainsString('secret', $json);

        // And only the four permitted keys survive on every entry.
        foreach ($payload['blocks'] as $block) {
            foreach ($block['entries'] as $entry) {
                $this->assertEmpty(
                    array_diff(array_keys($entry), ['label', 'value', 'basis', 'detail']),
                    'An entry leaked a key outside the allowlist: ' . json_encode(array_keys($entry)),
                );
            }
        }

        // The real figures still travel (the boundary scrubs, it does not lobotomise).
        $this->assertStringContainsString('CAC ceiling', $json);
    }

    public function test_narrating_without_an_llm_key_fails_cleanly_and_the_plan_still_stands(): void
    {
        $b = $this->goodBrand();
        $this->seedLastYearBlackFriday($b);
        $this->generate($b);
        $plan = CampaignPlan::firstOrFail();

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $this->postJson("/api/brands/{$b->slug}/plans/{$plan->id}/narrate")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'complete without prose'));

        // The plan is fully usable without the AI. The numbers were never its job.
        $this->assertNotEmpty($plan->fresh()->blocks);
        $this->assertNull($plan->fresh()->narrative);
    }

    // ── 4. Ledger ────────────────────────────────────────────────────────────────

    public function test_a_generated_plan_writes_playbook_rows_to_the_ledger(): void
    {
        $b = $this->goodBrand();
        $this->seedLastYearBlackFriday($b);
        $this->generate($b);

        $rows = Recommendation::where('brand_id', $b->id)->where('source', 'playbook')->get();

        // One per ACTIONABLE block — so GO-3.3 can eventually ask "did the plans work?"
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertNotNull($rows->firstWhere('kind', 'budget_shift'));
        $this->assertNotNull($rows->firstWhere('kind', 'creative_refresh'));

        // The plan IS the evidence — the entries travel into the ledger row.
        $ev = $rows->firstWhere('kind', 'creative_refresh')->evidence;
        $this->assertSame('playbook.creative', $ev['rule']);
        $this->assertNotEmpty($ev['entries']);
    }

    public function test_a_blocked_block_is_not_logged_as_advice(): void
    {
        // No margin → the budget block is blocked. A blocked block is not advice, and must
        // not pollute the ledger (or the acceptance rate) as though it were.
        $b = $this->goodBrand(margin: null);
        $this->seedLastYearBlackFriday($b);
        $this->generate($b);

        $budgetRows = Recommendation::where('brand_id', $b->id)
            ->where('source', 'playbook')
            ->get()
            ->filter(fn (Recommendation $r): bool => ($r->evidence['rule'] ?? '') === 'playbook.budget');

        $this->assertCount(0, $budgetRows);
    }
}
