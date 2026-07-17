<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The monthly client report builder. Verifies the report month resolves to the
 * last COMPLETE calendar month, the Overall picture KPIs carry D-005 revenue
 * (total_sales with refunds added back), the commerce heatmap cells reconcile
 * to the same basis, the YoY total/delta go NULL (never a zero-filled delta)
 * when the prior-year months aren't synced, the freshness gate is present with
 * the shared contract, and the gender/placement sections read from the STORED
 * meta_breakdown_daily rows (per-row fx in USD mode) — never a live Meta call.
 */
class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    /** Start of the report month — the last complete calendar month in the brand tz. */
    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfMonth()->subMonth();
    }

    private function makeBrand(): Brand
    {
        return Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => self::TZ,
            'status'        => 'active',
        ]);
    }

    /** Seed one daily_metrics row via DB::table with date-only strings (sqlite trap). */
    private function seedDaily(int $brandId, string $platform, string $date, array $cols, float $fx = 1.0): void
    {
        DB::table('daily_metrics')->insert(array_merge([
            'brand_id'       => $brandId,
            'platform'       => $platform,
            'date'           => $date,
            'currency'       => 'EUR',
            'fx_rate_to_usd' => $fx,
            'is_complete'    => true,
            'pulled_at'      => now(),
        ], $cols));
    }

    /** Seed one commerce_daily_metrics country row (slice 2.1 grain). */
    private function seedCommerce(int $brandId, string $date, string $country, float $totalSales, float $refunds, int $orders, float $fx = 1.0): void
    {
        DB::table('commerce_daily_metrics')->insert([
            'brand_id'        => $brandId,
            'date'            => $date,
            'dimension_type'  => 'country',
            'dimension_key'   => $country,
            'dimension_label' => $country,
            'orders'          => $orders,
            'total_sales'     => $totalSales,
            'net_sales'       => $totalSales * 0.9,
            'refunds_amount'  => $refunds,
            'currency'        => 'EUR',
            'fx_rate_to_usd'  => $fx,
            'is_complete'     => true,
            'pulled_at'       => now(),
        ]);
    }

    /** Seed one meta_breakdown_daily row (the stored breakdown pull, any platform). */
    private function seedBreakdown(int $brandId, string $date, string $type, string $segment, float $spend, float $value, int $impressions, int $clicks, float $fx = 1.0, string $platform = 'meta'): void
    {
        DB::table('meta_breakdown_daily')->insert([
            'brand_id'         => $brandId,
            'platform'         => $platform,
            'date'             => $date,
            'breakdown_type'   => $type,
            'segment_key'      => $segment,
            'segment_label'    => $segment,
            'spend'            => $spend,
            'impressions'      => $impressions,
            'clicks'           => $clicks,
            'reach'            => null,
            'conversions'      => 5,
            'conversion_value' => $value,
            'currency'         => 'EUR',
            'fx_rate_to_usd'   => $fx,
            'is_complete'      => true,
            'pulled_at'        => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** M0: one commerce_daily_metrics row for bulk-insert (mirrors seedCommerce's columns). */
    private function commerceRow(int $brandId, string $date, string $dimensionType, string $key, float $totalSales): array
    {
        return [
            'brand_id'        => $brandId,
            'date'            => $date,
            'dimension_type'  => $dimensionType,
            'dimension_key'   => $key,
            'dimension_label' => $key,
            'orders'          => 3,
            'total_sales'     => $totalSales,
            'net_sales'       => $totalSales * 0.9,
            'refunds_amount'  => 0,
            'currency'        => 'EUR',
            'fx_rate_to_usd'  => 1.0,
            'is_complete'     => true,
            'pulled_at'       => now(),
        ];
    }

    public function test_monthly_kpis_heatmap_d005_math_yoy_null_and_freshness(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        $monthStart  = $this->monthStart();
        $monthEnd    = $monthStart->endOfMonth();
        $reportMonth = $monthStart->format('Y-m');
        $momStart    = $monthStart->subMonth();

        // Report month: revenue lands on the month's LAST day so the freshness
        // gate (latest complete Shopify day ≥ month end) reads up to date.
        // D-005: total revenue = total_sales + refunds added back = 1000 + 100.
        $this->seedDaily($brand->id, 'shopify', $monthEnd->toDateString(), ['total_sales' => 1000, 'refunds_amount' => 100, 'orders' => 10]);
        $this->seedDaily($brand->id, 'meta', $monthStart->addDays(4)->toDateString(), ['spend' => 500, 'conversions' => 20, 'conversion_value' => 900]);

        // MoM month: 500 + 50 = 550 revenue on 250 spend.
        $this->seedDaily($brand->id, 'shopify', $momStart->addDays(9)->toDateString(), ['total_sales' => 500, 'refunds_amount' => 50, 'orders' => 5]);
        $this->seedDaily($brand->id, 'meta', $momStart->addDays(9)->toDateString(), ['spend' => 250, 'conversions' => 10, 'conversion_value' => 450]);

        // Commerce heatmap: one country row in the report month only — the
        // prior-year months are NOT synced, so YoY must be null, never 0-based.
        $this->seedCommerce($brand->id, $monthStart->addDays(4)->toDateString(), 'Spain', 700, 50, 7);

        // Stored Meta breakdowns for the report month (fix: read from the table,
        // no live API call — this test would fail on any HTTP attempt).
        $this->seedBreakdown($brand->id, $monthStart->addDays(4)->toDateString(), 'age_gender', '25-34 · female', 100, 400, 10000, 200);
        $this->seedBreakdown($brand->id, $monthStart->addDays(5)->toDateString(), 'age_gender', '25-34 · male', 50, 100, 5000, 50);
        $this->seedBreakdown($brand->id, $monthStart->addDays(4)->toDateString(), 'placement', 'instagram · feed', 120, 480, 8000, 160);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")
            ->assertOk()
            ->assertJsonPath('reportType', 'monthly')
            ->assertJsonPath('month.start', $monthStart->toDateString())
            ->assertJsonPath('month.end', $monthEnd->toDateString())
            ->assertJsonStructure([
                'overall'   => ['blendedRoas', 'revenue', 'adSpend'],
                'sections'  => ['countryRevenue', 'gender', 'placement'],
                'freshness' => ['upToDate', 'lastSynced', 'staleDays', 'windowEnd'],
            ]);

        // Overall picture — D-005 revenue with MoM delta, ROAS from USD columns.
        $this->assertEquals(1100.0, $res->json('overall.revenue.value'));
        $this->assertEquals(550.0, $res->json('overall.revenue.previous'));
        $this->assertEqualsWithDelta(100.0, $res->json('overall.revenue.deltaPct'), 0.1);
        $this->assertEquals(2.2, $res->json('overall.blendedRoas.value'));
        $this->assertEquals(500.0, $res->json('overall.adSpend.value'));

        // Commerce heatmap: the month cell carries the D-005 figure (700 + 50).
        $this->assertSame('ready', $res->json('sections.countryRevenue.status'));
        $row = $res->json('sections.countryRevenue.data.rows.0');
        $this->assertSame('Spain', $row['label']);
        $this->assertEquals(750.0, $row['byMonth'][$reportMonth]);
        $this->assertEquals(750.0, $row['total']);
        $this->assertEquals(750.0, $res->json('sections.countryRevenue.data.total'));

        // YoY: the prior-year months have no synced rows → total + delta are
        // NULL (rendered "—"), never a zero-filled +100% delta.
        $this->assertNull($row['yoyTotal']);
        $this->assertNull($row['deltaYoY']);

        // Freshness gate: latest complete Shopify day reaches the month end.
        $this->assertTrue($res->json('freshness.upToDate'));
        $this->assertSame($monthEnd->toDateString(), $res->json('freshness.lastSynced'));
        $this->assertSame(0, $res->json('freshness.staleDays'));
        $this->assertSame($monthEnd->toDateString(), $res->json('freshness.windowEnd'));

        // Gender section from the stored age_gender rows, folded onto gender —
        // per-platform shape: only Meta was seeded, so exactly one entry.
        $this->assertSame('ok', $res->json('sections.gender.status'));
        $genderPlatforms = $res->json('sections.gender.platforms');
        $this->assertCount(1, $genderPlatforms);
        $this->assertSame('meta', $genderPlatforms[0]['platform']);
        $gender = collect($genderPlatforms[0]['rows']);
        $this->assertSame('Female', $gender[0]['label']); // ranked by cost
        $this->assertEquals(100.0, $gender[0]['cost']);
        $this->assertEquals(4.0, $gender[0]['roas']);
        $this->assertEquals(10.0, $gender[0]['cpm']);
        $this->assertEqualsWithDelta(0.6667, $gender[0]['share'], 0.001);
        $this->assertSame('Male', $gender[1]['label']);
        $this->assertNull($gender[0]['reach']); // reach not captured → null, not 0

        // Placement section (Meta-only, same wrapped shape), prettified label.
        $this->assertSame('ok', $res->json('sections.placement.status'));
        $this->assertSame('meta', $res->json('sections.placement.platforms.0.platform'));
        $placement = $res->json('sections.placement.platforms.0.rows.0');
        $this->assertSame('IG · Feed', $placement['label']);
        $this->assertEquals(120.0, $placement['cost']);
        $this->assertEquals(4.0, $placement['roas']);
    }

    public function test_web_funnel_renders_rates_and_a_brand_wide_summary(): void
    {
        // Kanwar, 2026-07-17 (item 5) — the web funnel shows RATES, not raw counts:
        // added-to-cart / reached-checkout as % of sessions, completed-checkout as
        // % of those who reached checkout, plus a brand-wide summary row.
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $date  = $this->monthStart()->addDays(4)->toDateString();

        DB::table('shopify_funnel_daily')->insert([
            'brand_id' => $brand->id, 'date' => $date, 'dimension' => 'country',
            'segment_key' => 'ES', 'segment_label' => 'Spain',
            'sessions' => 1000, 'cart_additions' => 200, 'reached_checkout' => 100,
            'completed_checkout' => 40, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk()
            ->assertJsonPath('sections.funnelCountry.status', 'ready');

        $spain = collect($res->json('sections.funnelCountry.funnel'))->firstWhere('label', 'Spain');
        $this->assertNotNull($spain);
        $this->assertEquals(20.0, $spain['cartRate']);      // 200 / 1000
        $this->assertEquals(10.0, $spain['checkoutRate']);  // 100 / 1000
        $this->assertEquals(40.0, $spain['completedRate']); // 40 / 100 (of those who reached checkout)
        $this->assertEquals(4.0, $spain['cvr']);            // 40 / 1000

        $this->assertEquals(20.0, $res->json('sections.funnelCountry.summary.cartRate'));
        $this->assertEquals(4.0, $res->json('sections.funnelCountry.summary.cvr'));
    }

    public function test_financial_matrix_section_reuses_the_mom_s1_table(): void
    {
        // Kanwar, 2026-07-17 — v1 gains the full financial matrix by reusing the
        // MoM S1 section, so both reports show the identical table (last 6 months).
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $monthStart = $this->monthStart();

        $this->seedDaily($brand->id, 'shopify', $monthStart->addDays(3)->toDateString(), ['total_sales' => 4000, 'refunds_amount' => 100, 'orders' => 40]);
        $this->seedDaily($brand->id, 'meta', $monthStart->addDays(3)->toDateString(), ['spend' => 800, 'conversions' => 30, 'conversion_value' => 3600]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk()
            ->assertJsonPath('sections.financialMatrix.status', 'ok');

        // The trailing-6-month current-year table is present with the report month.
        $this->assertSame(6, $res->json('sections.financialMatrix.monthsWindow'));
        $this->assertNotEmpty($res->json('sections.financialMatrix.currentYearRows'));
    }

    public function test_sales_evolution_returns_a_daily_revenue_series(): void
    {
        // Kanwar, 2026-07-17 (item 3) — a daily revenue line for the report month.
        // (The modeled new/returning split rides on the same Shopify customer pull
        // §4 uses; with no Shopify connection here it's simply null, not fabricated.)
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $monthStart = $this->monthStart();

        $this->seedDaily($brand->id, 'shopify', $monthStart->addDays(3)->toDateString(), ['total_sales' => 500, 'refunds_amount' => 0, 'orders' => 5]);
        $this->seedDaily($brand->id, 'shopify', $monthStart->addDays(4)->toDateString(), ['total_sales' => 300, 'refunds_amount' => 0, 'orders' => 3]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk()
            ->assertJsonPath('sections.salesEvolution.status', 'ready');

        $this->assertEquals(800.0, $res->json('sections.salesEvolution.total'));
        $this->assertCount(2, $res->json('sections.salesEvolution.series'));
    }

    public function test_market_by_tier_groups_countries_into_the_brands_tiers(): void
    {
        // Kanwar, 2026-07-17 (item 4) — market revenue grouped by the brand's own
        // country tiers, table only. ES + FR both in Tier 1 → one Tier 1 row.
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();
        $date  = $this->monthStart()->addDays(4)->toDateString();

        $this->seedCommerce($brand->id, $date, 'Spain', 1000, 0, 10);
        $this->seedCommerce($brand->id, $date, 'France', 500, 0, 5);

        DB::table('country_tiers')->insert([
            'brand_id' => $brand->id, 'tier_key' => 'T1', 'label' => 'Tier 1', 'color' => '#111111',
            'countries' => json_encode(['ES', 'FR']), 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk()
            ->assertJsonPath('sections.marketTier.status', 'ready');

        $t1 = collect($res->json('sections.marketTier.data.rows'))->firstWhere('label', 'Tier 1');
        $this->assertNotNull($t1);
        $this->assertEquals(1500.0, $t1['total']); // ES 1000 + FR 500, report month
    }

    public function test_custom_range_collapses_the_monthly_matrices_to_range_vs_last_year(): void
    {
        // Kanwar, 2026-07-17 (item 1) — a period=custom from/to drives the whole
        // report off a sub-month range; the month-by-month matrices collapse to
        // range vs the same range last year, and month-only sections gate.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', self::TZ));
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        $this->seedCommerce($brand->id, '2026-06-05', 'Spain', 800, 0, 8);  // inside Jun 1–14
        $this->seedCommerce($brand->id, '2026-06-20', 'Spain', 400, 0, 4);  // outside the range
        $this->seedCommerce($brand->id, '2025-06-05', 'Spain', 500, 0, 5);  // same range last year

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly?period=custom&from=2026-06-01&to=2026-06-14&compare=last_year")
            ->assertOk()
            ->assertJsonPath('range', true)
            ->assertJsonPath('sections.countryRevenue.status', 'ready');

        $spain = collect($res->json('sections.countryRevenue.rangeCollapse.rows'))->first(fn ($r) => $r[0]['v'] === 'Spain');
        $this->assertNotNull($spain);
        $this->assertEquals(800.0, $spain[1]['v']); // range revenue (only in-range day)
        $this->assertEquals(500.0, $spain[2]['v']); // same range last year

        // Month-granular section gates honestly in range mode.
        $this->assertSame('coming', $res->json('sections.newVsExisting.status'));

        CarbonImmutable::setTestNow();
    }

    public function test_freshness_gates_when_the_month_is_not_fully_synced(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        $monthEnd = $this->monthStart()->endOfMonth();

        // Latest complete Shopify day stops 5 days short of the month end.
        $this->seedDaily($brand->id, 'shopify', $monthEnd->subDays(5)->toDateString(), ['total_sales' => 100, 'orders' => 1]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk();

        $this->assertFalse($res->json('freshness.upToDate'));
        $this->assertSame(5, $res->json('freshness.staleDays'));
        $this->assertSame($monthEnd->subDays(5)->toDateString(), $res->json('freshness.lastSynced'));
    }

    public function test_gender_section_converts_per_row_fx_in_usd_mode(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        $monthStart = $this->monthStart();

        // 100 EUR spend at a stored 1.25 fx snapshot → 125 USD.
        $this->seedBreakdown($brand->id, $monthStart->addDays(3)->toDateString(), 'age_gender', '25-34 · female', 100, 400, 10000, 200, fx: 1.25);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly?currency=USD")->assertOk();

        $this->assertSame('USD', $res->json('currency'));
        $this->assertSame('ok', $res->json('sections.gender.status'));
        $this->assertEquals(125.0, $res->json('sections.gender.platforms.0.rows.0.cost'));
        $this->assertEquals(500.0, $res->json('sections.gender.platforms.0.rows.0.roas') * 125.0); // revenue 400 × 1.25
    }

    public function test_gender_section_lists_each_platform_with_age_gender_rows(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        $monthStart = $this->monthStart();

        // Meta uses "AGE · gender" keys; TikTok lands bare upper-case values.
        // Both fold through the same case-insensitive genderOf.
        $this->seedBreakdown($brand->id, $monthStart->addDays(3)->toDateString(), 'age_gender', '25-34 · female', 100, 400, 10000, 200);
        $this->seedBreakdown($brand->id, $monthStart->addDays(3)->toDateString(), 'age_gender', 'FEMALE', 80, 240, 8000, 160, platform: 'tiktok');
        $this->seedBreakdown($brand->id, $monthStart->addDays(4)->toDateString(), 'age_gender', 'MALE', 20, 20, 2000, 20, platform: 'tiktok');
        $this->seedBreakdown($brand->id, $monthStart->addDays(4)->toDateString(), 'age_gender', 'NONE', 10, 0, 1000, 5, platform: 'tiktok');

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk();

        $this->assertSame('ok', $res->json('sections.gender.status'));
        $platforms = collect($res->json('sections.gender.platforms'))->keyBy('platform');
        $this->assertCount(2, $platforms);
        $this->assertArrayHasKey('meta', $platforms->all());
        $this->assertArrayHasKey('tiktok', $platforms->all());

        // TikTok rows fold onto the same gender axis: Female first (by cost),
        // then Male, and 'NONE' lands in the unknown bucket.
        $tiktok = collect($platforms['tiktok']['rows']);
        $this->assertSame(['Female', 'Male', 'Unknown'], $tiktok->pluck('label')->all());
        $this->assertEquals(80.0, $tiktok[0]['cost']);
        $this->assertEquals(3.0, $tiktok[0]['roas']);
        $this->assertEqualsWithDelta(0.7273, $tiktok[0]['share'], 0.001); // 80 / 110

        // Meta's row shape is unchanged by the wrapping.
        $this->assertEquals(100.0, $platforms['meta']['rows'][0]['cost']);
        $this->assertSame('Female', $platforms['meta']['rows'][0]['label']);
    }

    public function test_month_selector_shifts_the_report_month_and_lists_available_months(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        $defaultStart = $this->monthStart();          // last complete month
        $olderStart   = $defaultStart->subMonth();    // the month before it
        $olderEnd     = $olderStart->endOfMonth();

        // Rows in BOTH months; the older month carries distinct revenue so the
        // selection is visible in the KPIs. Its MoM month (older-1) is empty →
        // previous null, never 0.
        $this->seedDaily($brand->id, 'shopify', $defaultStart->addDays(2)->toDateString(), ['total_sales' => 900, 'refunds_amount' => 0, 'orders' => 9]);
        $this->seedDaily($brand->id, 'shopify', $olderStart->addDays(2)->toDateString(), ['total_sales' => 300, 'refunds_amount' => 30, 'orders' => 3]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly?month={$olderStart->format('Y-m')}")
            ->assertOk()
            ->assertJsonPath('month.start', $olderStart->toDateString())
            ->assertJsonPath('month.end', $olderEnd->toDateString());

        // KPIs are for the SELECTED month; the MoM comparison shifts with it —
        // the month before the selection is unsynced, so no delta is invented.
        $this->assertEquals(330.0, $res->json('overall.revenue.value'));
        $this->assertNull($res->json('overall.revenue.deltaPct'));

        // Picker lists every complete month down to the earliest synced row,
        // most recent first — both seeded months appear.
        $keys = collect($res->json('availableMonths'))->pluck('key')->all();
        $this->assertSame([$defaultStart->format('Y-m'), $olderStart->format('Y-m')], $keys);
        $this->assertSame($olderStart->isoFormat('MMMM YYYY'), $res->json('availableMonths.1.label'));

        // An incomplete month (the current one) is refused → default month.
        $current = CarbonImmutable::now(self::TZ)->format('Y-m');
        $this->getJson("/api/brands/{$brand->slug}/reports/monthly?month={$current}")
            ->assertOk()
            ->assertJsonPath('month.start', $defaultStart->toDateString());
    }

    public function test_empty_brand_degrades_cleanly_with_a_closed_freshness_gate(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk();

        // Nothing synced → the gate holds the report back; sections read as
        // no_data / needs_source, never fabricated zeros.
        $this->assertFalse($res->json('freshness.upToDate'));
        $this->assertNull($res->json('freshness.lastSynced'));
        $this->assertSame('no_data', $res->json('sections.countryRevenue.status'));
        $this->assertSame('no_data', $res->json('sections.gender.status'));
        $this->assertSame('no_data', $res->json('sections.placement.status'));
        $this->assertNull($res->json('overall.blendedRoas.value'));
    }

    /**
     * M0 regression guard. Root cause of the new-polinesia freeze was the ONE
     * live external call in build() (customersByMonthRange) with no bounded
     * timeout — covered separately below. This test guards the OTHER failure
     * mode the same investigation surfaced: monthMetrics() was called twice
     * directly (cur/mom) plus once per trailing month inside
     * newVsExistingSection()'s loop — 2 of those 6 loop iterations are exact
     * duplicates of the direct cur/mom windows (same brandId/start/end/usd),
     * so 8 calls computed only 6 distinct windows. Also guards against the
     * commerce sections (country/product/category, all high-cardinality —
     * MonthlySeries' own doc comment cites "2,600+ products") degrading from
     * GROUP BY aggregation into a per-row loop, which is exactly the "monolith
     * payload that doesn't scale" M5 rules out.
     *
     * The 120-query / 400KB ceilings are generous order-of-magnitude backstops
     * reasoned from the section list (13 sections + monthMetrics + freshness +
     * availableMonths, each a small constant number of queries), NOT a tightly
     * measured baseline — this environment cannot run `php artisan test`
     * (composer/vendor unavailable). Kanwar: please run this once for real and
     * tell me the actual query count so I can tighten these.
     */
    public function test_heavy_brand_query_count_and_payload_stay_bounded_by_row_cardinality(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = $this->makeBrand();

        $monthStart = $this->monthStart();
        // The 6 trailing Y-m windows MonthlyReport::TRAILING_MONTHS builds.
        $trailingMonths = [];
        for ($i = 5; $i >= 0; $i--) {
            $trailingMonths[] = $monthStart->subMonths($i);
        }

        foreach ($trailingMonths as $ms) {
            $me = $ms->endOfMonth();
            // Revenue + spend on every trailing month so sections read
            // 'ok'/'ready', never 'no_data' — a no_data short-circuit would
            // make the query-count assertion meaningless (too cheap to prove
            // anything about the aggregation path).
            $this->seedDaily($brand->id, 'shopify', $me->toDateString(), ['total_sales' => 1000, 'refunds_amount' => 50, 'orders' => 20]);
            $this->seedDaily($brand->id, 'meta', $ms->addDays(3)->toDateString(), ['spend' => 300, 'conversions' => 10, 'conversion_value' => 600]);
            $this->seedDaily($brand->id, 'google', $ms->addDays(4)->toDateString(), ['spend' => 200, 'conversions' => 8, 'conversion_value' => 400]);
            $this->seedDaily($brand->id, 'tiktok', $ms->addDays(5)->toDateString(), ['spend' => 100, 'conversions' => 4, 'conversion_value' => 150]);

            // High-cardinality commerce dimensions: 15 countries + 20 products +
            // 6 categories per month = 246 rows/month, 1,476 total across 6
            // months — bulk-inserted (not one row at a time) to keep the test fast.
            $rows = [];
            for ($c = 0; $c < 15; $c++) {
                $rows[] = $this->commerceRow($brand->id, $ms->addDays(6)->toDateString(), 'country', "C{$c}", 50 + $c);
            }
            for ($p = 0; $p < 20; $p++) {
                $rows[] = $this->commerceRow($brand->id, $ms->addDays(6)->toDateString(), 'product', "SKU-{$p}", 20 + $p);
            }
            for ($cat = 0; $cat < 6; $cat++) {
                $rows[] = $this->commerceRow($brand->id, $ms->addDays(6)->toDateString(), 'category', "Cat-{$cat}", 80 + $cat);
            }
            DB::table('commerce_daily_metrics')->insert($rows);
        }

        Sanctum::actingAs($user);
        DB::enableQueryLog();
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/monthly")->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertLessThan(
            120,
            $queryCount,
            "Query count ({$queryCount}) for 1,476 commerce rows suggests a per-row loop, not GROUP BY aggregation."
        );

        // MonthlySeries::forDimension caps every commerce section at top-8 +
        // one "other" rollup regardless of catalogue size, so payload size
        // should not scale with the 1,476 seeded rows either.
        $payloadBytes = strlen($res->getContent());
        $this->assertLessThan(
            400000,
            $payloadBytes,
            "Payload ({$payloadBytes} bytes) suggests section output isn't capped at top-N."
        );
    }

    /**
     * M0 root-cause regression guard. Pins the constant (not a live call — no
     * Shopify connection is seeded, so this never hits the network) so a
     * future edit that raises it back toward Guzzle's 30s default fails
     * loudly here instead of silently reintroducing the report freeze on
     * whichever brand has the slowest ShopifyQL response that day.
     */
    public function test_shopify_customer_pull_uses_a_bounded_report_context_timeout(): void
    {
        $timeout = (new \ReflectionClass(\App\Platforms\Shopify\RevenueFetcher::class))
            ->getConstant('REPORT_CONTEXT_TIMEOUT_SECS');

        $this->assertIsInt($timeout);
        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(15, $timeout);
    }
}
