<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EmailDailyMetric;
use App\Platforms\Klaviyo\RevenueFetcher;
use App\Platforms\Support\PlatformRateLimitedException;
use App\Services\PlatformCredentialService;
use App\Services\Sync\KlaviyoSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GO-1.1 Klaviyo email revenue. Fetcher parses metric-aggregates (flow + campaign)
 * with brand tz + currency passthrough and missing≠0; 429 → PlatformRateLimitedException;
 * sync upserts idempotently; the private key is BRAND-SCOPED in platform_credentials.
 */
final class KlaviyoRevenueTest extends TestCase
{
    use RefreshDatabase;

    private function setKey(Brand $brand, string $key = 'pk_test_key_123456'): void
    {
        app(PlatformCredentialService::class)->set('klaviyo', 'private_key', $key, brandId: (int) $brand->id);
    }

    /** metrics/ → Placed Order id; metric-aggregates/ → per-`by` response. */
    private function fakeKlaviyo(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'metric-aggregates')) {
                $by = $request->data()['data']['attributes']['by'][0] ?? '';
                if ($by === '$attributed_flow') {
                    return Http::response(['data' => ['attributes' => [
                        'dates' => ['2026-06-01T00:00:00+02:00', '2026-06-02T00:00:00+02:00'],
                        'data'  => [
                            ['dimensions' => ['F1'], 'measurements' => ['sum_value' => [100.0, 0], 'count' => [2, 0]]],
                            ['dimensions' => ['null'], 'measurements' => ['sum_value' => [999.0], 'count' => [9]]], // unattributed → skipped
                        ],
                    ]]]);
                }

                return Http::response(['data' => ['attributes' => [
                    'dates' => ['2026-06-01T00:00:00+02:00'],
                    'data'  => [['dimensions' => ['C1'], 'measurements' => ['sum_value' => [50.0], 'count' => [1]]]],
                ]]]);
            }

            // GET /metrics/
            return Http::response(['data' => [['id' => 'M1', 'attributes' => ['name' => 'Placed Order']]]]);
        });
    }

    public function test_fetcher_parses_flow_and_campaign_with_tz_and_currency(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => 'Europe/Madrid']);
        $this->setKey($brand);
        $this->fakeKlaviyo();

        $rows = app(RevenueFetcher::class)->fetchRange(
            $brand, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'),
        );

        // flow F1 day 1 (100/2), campaign C1 day 1 (50/1). Day-2 zero + unattributed skipped.
        $this->assertCount(2, $rows);
        $flow = collect($rows)->firstWhere('source', 'flow');
        $this->assertSame('F1', $flow['source_id']);
        $this->assertSame(2, $flow['conversions']);
        $this->assertEqualsWithDelta(100.0, $flow['conversion_value'], 0.001);
        $this->assertSame('EUR', $flow['currency']);          // brand currency, no Klaviyo conversion
        $this->assertSame('campaign', collect($rows)->firstWhere('source_id', 'C1')['source']);
    }

    public function test_rate_limit_becomes_platform_exception(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD']);
        $this->setKey($brand);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'metric-aggregates')) {
                return Http::response([], 429, ['Retry-After' => '30']);
            }

            return Http::response(['data' => [['id' => 'M1', 'attributes' => ['name' => 'Placed Order']]]]);
        });

        try {
            app(RevenueFetcher::class)->fetchRange($brand, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-01'));
            $this->fail('Expected PlatformRateLimitedException');
        } catch (PlatformRateLimitedException $e) {
            $this->assertSame('klaviyo', $e->platform);
            $this->assertSame(30, $e->retryAfterSeconds);
        }
    }

    public function test_sync_upserts_email_rows_idempotently(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => 'Europe/Madrid']);
        $this->setKey($brand);
        $this->fakeKlaviyo();
        $sync = app(KlaviyoSync::class);

        $n = $sync->syncRange($brand, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));
        $this->assertSame(2, $n);
        $this->assertSame(2, EmailDailyMetric::where('brand_id', $brand->id)->count());

        // Idempotent: re-run does not duplicate (unique brand+date+source+source_id).
        $sync->syncRange($brand, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-02'));
        $this->assertSame(2, EmailDailyMetric::where('brand_id', $brand->id)->count());

        $this->assertDatabaseHas('email_daily_metrics', [
            'brand_id' => $brand->id, 'source' => 'flow', 'source_id' => 'F1', 'currency' => 'EUR', 'conversions' => 2,
        ]);
    }

    /**
     * The honesty law (master plan §0.1): email revenue ships its own channel block
     * WITH the attribution honesty box, and is NEVER folded into total revenue.
     */
    public function test_weekly_report_email_block_ships_honesty_box_and_is_never_summed(): void
    {
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);

        // A day inside the last COMPLETE Mon–Sun week (what the weekly report reads).
        $day = CarbonImmutable::now('UTC')
            ->startOfWeek(\Carbon\CarbonInterface::MONDAY)
            ->subWeek()
            ->startOfDay()
            ->toDateString();

        // Store revenue 1000 (Shopify) and email revenue 300 (Klaviyo) on the same day.
        \Illuminate\Support\Facades\DB::table('daily_metrics')->insert([
            'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $day,
            'total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 10,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('email_daily_metrics')->insert([
            'brand_id' => $brand->id, 'date' => $day, 'source' => 'flow', 'source_id' => 'F1',
            'source_name' => 'Welcome flow', 'conversions' => 3, 'conversion_value' => 300,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ]);

        Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'master_admin']));
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/weekly")->assertOk()->json();

        // Its own channel, with the mandatory honesty box.
        $this->assertNotNull($res['email']);
        $this->assertEqualsWithDelta(300.0, (float) $res['email']['revenue'], 0.001);
        $this->assertSame(3, $res['email']['orders']);
        $this->assertNotEmpty($res['email']['honestyBox']);
        $this->assertStringContainsString('own channel', $res['email']['honestyBox']);

        // THE INVARIANT: email revenue is NOT added to store revenue (1000, not 1300).
        $this->assertEqualsWithDelta(1000.0, (float) $res['kpis']['totalRevenue']['value'], 0.001);

        // Ratio, not an additive split: 300/1000 = 30%.
        $this->assertEqualsWithDelta(30.0, (float) $res['email']['shareOfStore'], 0.001);
    }

    public function test_weekly_report_email_is_null_when_no_klaviyo_data(): void
    {
        // Missing ≠ 0: a brand with no Klaviyo rows gets a NULL block, never a €0 one.
        $brand = Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'master_admin']));

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/weekly")->assertOk()->json();
        $this->assertNull($res['email']);
    }

    public function test_key_is_brand_scoped(): void
    {
        $a = Brand::factory()->create();
        $b = Brand::factory()->create();
        $svc = app(PlatformCredentialService::class);

        $svc->set('klaviyo', 'private_key', 'pk_A', brandId: (int) $a->id);
        $svc->set('klaviyo', 'private_key', 'pk_B', brandId: (int) $b->id);

        // Each brand reads its own; neither leaks to the other or to agency scope.
        $this->assertSame('pk_A', $svc->get('klaviyo', 'private_key', (int) $a->id));
        $this->assertSame('pk_B', $svc->get('klaviyo', 'private_key', (int) $b->id));
        $this->assertTrue($svc->has('klaviyo', 'private_key', (int) $a->id));
        $this->assertFalse($svc->has('klaviyo', 'private_key'));           // agency scope: none
        $this->assertSame(2, \App\Models\PlatformCredential::where('platform', 'klaviyo')->where('status', 'active')->count());
    }
}
