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

    /** Seed one meta_breakdown_daily row (the stored breakdown pull). */
    private function seedBreakdown(int $brandId, string $date, string $type, string $segment, float $spend, float $value, int $impressions, int $clicks, float $fx = 1.0): void
    {
        DB::table('meta_breakdown_daily')->insert([
            'brand_id'         => $brandId,
            'platform'         => 'meta',
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

        // Gender section from the stored age_gender rows, folded onto gender.
        $this->assertSame('ready', $res->json('sections.gender.status'));
        $gender = collect($res->json('sections.gender.metrics'));
        $this->assertSame('Female', $gender[0]['label']); // ranked by cost
        $this->assertEquals(100.0, $gender[0]['cost']);
        $this->assertEquals(4.0, $gender[0]['roas']);
        $this->assertEquals(10.0, $gender[0]['cpm']);
        $this->assertEqualsWithDelta(0.6667, $gender[0]['share'], 0.001);
        $this->assertSame('Male', $gender[1]['label']);
        $this->assertNull($gender[0]['reach']); // reach not captured → null, not 0

        // Placement section from the stored placement rows, prettified label.
        $this->assertSame('ready', $res->json('sections.placement.status'));
        $placement = $res->json('sections.placement.placement.0');
        $this->assertSame('IG · Feed', $placement['label']);
        $this->assertEquals(120.0, $placement['cost']);
        $this->assertEquals(4.0, $placement['roas']);
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
        $this->assertSame('ready', $res->json('sections.gender.status'));
        $this->assertEquals(125.0, $res->json('sections.gender.metrics.0.cost'));
        $this->assertEquals(500.0, $res->json('sections.gender.metrics.0.roas') * 125.0); // revenue 400 × 1.25
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
}
