<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Platforms\Google\ReportsFetcher as GoogleReportsFetcher;
use App\Platforms\Meta\AdProductFetcher;
use App\Platforms\Meta\InsightsFetcher;
use App\Platforms\TikTok\ReportsFetcher as TikTokReportsFetcher;
use App\Services\Currency\FxService;
use App\Services\Sync\CampaignSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Inventory Intelligence honesty contract (2026-07-10): missing data is NULL,
 * never 0 — but a product with genuinely no sales/spend in a COVERED window IS
 * 0. Plus: inactive products excluded, custom windows clamp to yesterday,
 * ROAS is fx-correct (USD sums), freshness surfaced via dataThrough, and the
 * daily sync + backfill wiring that keeps ad_product_daily fresh.
 */
final class InventoryQueryTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->brand = Brand::factory()->create([
            'timezone' => 'UTC', 'base_currency' => 'EUR', 'status' => 'active',
        ]);
    }

    private function url(string $qs = ''): string
    {
        return "/api/brands/{$this->brand->slug}/inventory" . ($qs !== '' ? "?{$qs}" : '');
    }

    private function yesterday(): string
    {
        return CarbonImmutable::now('UTC')->subDay()->toDateString();
    }

    /** @param array<string, mixed> $overrides */
    private function seedProduct(array $overrides = []): void
    {
        DB::table('product_catalog')->insert(array_merge([
            'brand_id'        => $this->brand->id,
            'product_id'      => 'gid://shopify/Product/1',
            'handle'          => 'red-shoe',
            'title'           => 'Red Shoe',
            'product_type'    => null,
            'status'          => 'active', // shopify:sync-catalog stores statuses lowercased
            'tags'            => json_encode([]),
            'variant_count'   => 1,
            'total_inventory' => 50,
            'variants'        => json_encode([['t' => 'Default', 'q' => 50]]),
            'captured_at'     => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function seedAdRow(array $overrides = []): void
    {
        DB::table('ad_product_daily')->insert(array_merge([
            'brand_id'       => $this->brand->id,
            'date'           => $this->yesterday(),
            'platform'       => 'meta',
            'product_key'    => 'red-shoe',
            'spend'          => 100.0,
            'ads_count'      => 2,
            'currency'       => 'EUR',
            'fx_rate_to_usd' => 1.10,
            'is_complete'    => true,
            'pulled_at'      => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function seedCommerceRow(array $overrides = []): void
    {
        DB::table('commerce_daily_metrics')->insert(array_merge([
            'brand_id'        => $this->brand->id,
            'date'            => $this->yesterday(),
            'dimension_type'  => 'product',
            'dimension_key'   => 'Red Shoe',
            'dimension_label' => 'Red Shoe',
            'orders'          => 3,
            'units'           => 5,
            'net_sales'       => 200.0,
            'total_sales'     => 220.0,
            'refunds_amount'  => 0.0,
            'currency'        => 'EUR',
            'fx_rate_to_usd'  => 1.10,
            'is_complete'     => true,
            'pulled_at'       => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));
    }

    // ── Missing ≠ 0 ──────────────────────────────────────────────────────────

    public function test_no_data_in_window_yields_nulls_not_zeros(): void
    {
        $this->seedProduct(); // catalog only — no ad rows, no commerce rows

        $json = $this->getJson($this->url())->assertOk()->json();

        // Spend side: NULL, not €0 — the table was never filled for the window.
        $this->assertNull($json['summary']['adSpend']);
        $this->assertNull($json['summary']['attributedSpend']);
        $this->assertNull($json['summary']['roas']);
        $this->assertNull($json['unattributed']);
        // Commerce side too.
        $this->assertNull($json['summary']['units']);
        $this->assertNull($json['summary']['unitsPrev']);
        $this->assertNull($json['summary']['revenue']);

        $row = $json['products'][0];
        $this->assertNull($row['spend']);
        $this->assertNull($row['roas']);
        $this->assertNull($row['ads']);
        $this->assertNull($row['units']);
        $this->assertNull($row['unitsPrev']);
        $this->assertNull($row['deltaPct']);
        $this->assertNull($row['revenue']);
        // Without spend data, 'no_spend' is meaningless — stock-based only.
        $this->assertSame('ok', $row['action']);
    }

    public function test_covered_window_keeps_true_zero_for_unadvertised_product(): void
    {
        $this->seedProduct();
        $this->seedProduct(['product_id' => 'gid://shopify/Product/2', 'handle' => 'blue-shoe', 'title' => 'Blue Shoe']);
        $this->seedAdRow();       // spend on red-shoe only → window IS covered
        $this->seedCommerceRow(); // sales for Red Shoe only

        $json = $this->getJson($this->url())->assertOk()->json();
        $rows = collect($json['products'])->keyBy('handle');

        // Covered window: absence is a real 0, not null — that's the distinction.
        $this->assertSame(0.0, (float) $rows['blue-shoe']['spend']);
        $this->assertSame(0, $rows['blue-shoe']['ads']);
        $this->assertSame(0, $rows['blue-shoe']['units']);
        $this->assertSame('no_spend', $rows['blue-shoe']['action']);

        $this->assertEqualsWithDelta(100.0, (float) $rows['red-shoe']['spend'], 0.001);
        $this->assertSame('ok', $rows['red-shoe']['action']);
        $this->assertEqualsWithDelta(100.0, (float) $json['summary']['adSpend'], 0.001);
        $this->assertSame(['collection' => 0, 'other' => 0, 'total' => 0], array_map('intval', $json['unattributed']));
    }

    // ── Inactive products excluded ───────────────────────────────────────────

    public function test_non_active_products_are_excluded_from_rows_and_counts(): void
    {
        $this->seedProduct(['total_inventory' => 10]); // active, alert tier
        $this->seedProduct(['product_id' => 'p2', 'handle' => 'old-draft', 'title' => 'Old Draft', 'status' => 'draft', 'total_inventory' => 99]);
        $this->seedProduct(['product_id' => 'p3', 'handle' => 'gone', 'title' => 'Gone', 'status' => 'archived', 'total_inventory' => 7]);

        $json = $this->getJson($this->url())->assertOk()->json();

        $this->assertCount(1, $json['products']);
        $this->assertSame('red-shoe', $json['products'][0]['handle']);
        $this->assertSame(2, $json['excludedInactive']);
        $this->assertSame(1, $json['summary']['products']);
        $this->assertSame(10, $json['summary']['netStock']); // draft/archived stock not counted
        $this->assertSame(1, $json['summary']['alert']);
        $this->assertSame(0, $json['summary']['pause']);
        $this->assertSame(0, $json['summary']['ok']);
    }

    // ── Window clamping ──────────────────────────────────────────────────────

    public function test_custom_to_today_clamps_to_yesterday(): void
    {
        $today     = CarbonImmutable::now('UTC')->toDateString();
        $yesterday = $this->yesterday();
        $from      = CarbonImmutable::now('UTC')->subDays(4)->toDateString();

        $json = $this->getJson($this->url("period=custom&from={$from}&to={$today}"))
            ->assertOk()->json();

        $this->assertSame($from, $json['from']);
        $this->assertSame($yesterday, $json['to']); // today is partial → clamped
    }

    // ── Currency-correct ROAS ────────────────────────────────────────────────

    public function test_roas_uses_usd_sums_and_flags_currency_mismatch(): void
    {
        // EUR brand; the Meta account bills USD (fx 1.0), the store sells EUR
        // (fx 1.10). Native ROAS 220/100 = 2.20 would be wrong — USD-correct is
        // (220 × 1.10) / (100 × 1.0) = 2.42.
        $this->seedProduct();
        $this->seedAdRow(['currency' => 'USD', 'fx_rate_to_usd' => 1.0]);
        $this->seedCommerceRow(); // EUR, fx 1.10, total 220

        $json = $this->getJson($this->url())->assertOk()->json();
        $row  = $json['products'][0];

        $this->assertEqualsWithDelta(2.42, (float) $row['roas'], 0.001);
        $this->assertEqualsWithDelta(2.42, (float) $json['summary']['roas'], 0.001);
        // Displayed money stays native sums.
        $this->assertEqualsWithDelta(100.0, (float) $row['spend'], 0.001);
        $this->assertEqualsWithDelta(220.0, (float) $row['revenue'], 0.001);
        $this->assertTrue($json['spendCurrencyMismatch']);
    }

    public function test_matching_ad_currency_reports_no_mismatch(): void
    {
        $this->seedProduct();
        $this->seedAdRow(); // EUR, matches brand base
        $this->seedCommerceRow();

        $json = $this->getJson($this->url())->assertOk()->json();

        $this->assertFalse($json['spendCurrencyMismatch']);
        // Same fx on both sides (1.10) → USD ROAS equals native 220/100.
        $this->assertEqualsWithDelta(2.20, (float) $json['summary']['roas'], 0.001);
    }

    // ── Freshness surface ────────────────────────────────────────────────────

    public function test_data_through_reports_seeded_maxima(): void
    {
        $capturedAt = CarbonImmutable::parse('2026-07-08 14:10:00', 'UTC');
        $this->seedProduct(['captured_at' => $capturedAt]);

        // Maxima are UNBOUNDED (not window-limited): the ad max sits inside the
        // last7 window, the commerce max 60 days back — both must surface.
        $this->seedAdRow(['date' => CarbonImmutable::now('UTC')->subDays(3)->toDateString()]);
        $this->seedAdRow(['date' => $this->yesterday()]);
        $oldDate = CarbonImmutable::now('UTC')->subDays(60)->toDateString();
        $this->seedCommerceRow(['date' => $oldDate]);

        $json = $this->getJson($this->url())->assertOk()->json();

        $this->assertSame($this->yesterday(), $json['dataThrough']['adSpend']);
        $this->assertSame($oldDate, $json['dataThrough']['commerce']);
        $this->assertSame($capturedAt->toIso8601String(), $json['dataThrough']['catalog']);
        // Legacy key kept for FE compatibility.
        $this->assertSame($json['dataThrough']['catalog'], $json['syncedAt']);
    }

    // ── Daily-sync wiring (CampaignSync::syncMetaAdProducts) ─────────────────

    private function campaignSync(AdProductFetcher $fetcher): CampaignSync
    {
        return new CampaignSync(
            app(InsightsFetcher::class),
            app(GoogleReportsFetcher::class),
            app(TikTokReportsFetcher::class),
            app(FxService::class),
            $fetcher,
        );
    }

    private function metaConnection(): PlatformConnection
    {
        $conn = (new PlatformConnection())->forceFill([
            'brand_id'    => $this->brand->id,
            'platform'    => 'meta',
            'external_id' => 'act_1',
            'status'      => 'active',
            'credentials' => ['k' => 'v'],
        ]);
        $conn->save();
        $conn->setRelation('brand', $this->brand);

        return $conn;
    }

    public function test_sync_meta_ad_products_upserts_the_days_rows(): void
    {
        $conn = $this->metaConnection();
        $date = CarbonImmutable::parse($this->yesterday(), 'UTC');

        $fetcher = Mockery::mock(AdProductFetcher::class);
        $fetcher->shouldReceive('fetchDailyByProduct')
            ->once()
            ->withArgs(fn ($c, $f, $t) => $c->is($conn)
                && $f->toDateString() === $date->toDateString()
                && $t->toDateString() === $date->toDateString())
            ->andReturn([
                ['date' => $date->toDateString(), 'key' => 'red-shoe', 'spend' => 12.5, 'ads' => 2, 'currency' => 'EUR'],
                ['date' => $date->toDateString(), 'key' => '__other', 'spend' => 3.0, 'ads' => 1, 'currency' => 'EUR'],
            ]);

        $written = $this->campaignSync($fetcher)->syncMetaAdProducts($conn, $date);

        $this->assertSame(2, $written);
        $this->assertDatabaseHas('ad_product_daily', [
            'brand_id' => $this->brand->id, 'product_key' => 'red-shoe',
            'spend' => 12.5, 'ads_count' => 2, 'currency' => 'EUR',
        ]);
        $this->assertDatabaseHas('ad_product_daily', [
            'brand_id' => $this->brand->id, 'product_key' => '__other', 'spend' => 3.0,
        ]);
    }

    public function test_sync_meta_ad_products_is_meta_only_and_fault_isolated(): void
    {
        $date = CarbonImmutable::parse($this->yesterday(), 'UTC');

        // Non-meta connection → no-op, fetcher never touched.
        $shopify = (new PlatformConnection())->forceFill([
            'brand_id' => $this->brand->id, 'platform' => 'shopify',
            'external_id' => 's.myshopify.com', 'status' => 'active', 'credentials' => ['k' => 'v'],
        ]);
        $shopify->save();
        $untouched = Mockery::mock(AdProductFetcher::class); // no expectations — any call fails
        $this->assertSame(0, $this->campaignSync($untouched)->syncMetaAdProducts($shopify, $date));

        // Fetcher blowing up → logged + swallowed (0 rows), never rethrown —
        // a product-spend hiccup must not fail the day's main sync.
        $conn    = $this->metaConnection();
        $broken  = Mockery::mock(AdProductFetcher::class);
        $broken->shouldReceive('fetchDailyByProduct')->once()->andThrow(new RuntimeException('Meta error 17'));

        $this->assertSame(0, $this->campaignSync($broken)->syncMetaAdProducts($conn, $date));
        $this->assertSame(0, DB::table('ad_product_daily')->count());
    }

    public function test_ad_spend_sums_every_platform_and_names_the_ones_it_used(): void
    {
        // The page shipped calling this column "Meta spend" and captioning it "Meta only", while
        // the query summed EVERY platform in ad_product_daily. The number was right; the label
        // was false. This pins the number AND the disclosure.
        //
        // Do NOT "fix" this by filtering to platform = 'meta'. Product ROAS divides all-channel
        // Shopify revenue by product ad spend — a Meta-only denominator OVERSTATES ROAS for any
        // brand also running Google or TikTok. The complete denominator is the honest one.
        $this->seedProduct();
        $this->seedAdRow(['platform' => 'meta',   'spend' => 100.0]);
        $this->seedAdRow(['platform' => 'google', 'spend' => 40.0]);
        $this->seedAdRow(['platform' => 'tiktok', 'spend' => 10.0]);

        $json = $this->getJson($this->url())->assertOk()->json();

        // 100 + 40 + 10 — the whole cost of advertising this product, not Meta's share of it.
        $this->assertEqualsWithDelta(150.0, (float) $json['summary']['adSpend'], 0.001);

        $row = collect($json['products'])->firstWhere('handle', 'red-shoe');
        $this->assertEqualsWithDelta(150.0, (float) $row['spend'], 0.001);

        // Biggest spender first, so the UI can name exactly what's in the number.
        $this->assertSame(['meta', 'google', 'tiktok'], $json['spendPlatforms']);
    }

    public function test_spend_platforms_is_empty_when_the_brand_has_no_ad_rows(): void
    {
        // No ad rows → spend is null (never €0), and the UI has nothing to name, so it says
        // "ad platforms" rather than inventing "Meta".
        $this->seedProduct();

        $json = $this->getJson($this->url())->assertOk()->json();

        $this->assertNull($json['summary']['adSpend']);
        $this->assertSame([], $json['spendPlatforms']);
    }
}
