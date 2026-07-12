<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Brand;
use App\Models\User;
use App\Services\Rules\AnomalyScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GO-2.4 — the anomaly feed. Deterministic rules against a trailing 28-day MEDIAN.
 *
 * Every rule is tested at AND below its threshold. A rule that only fires is half a
 * rule: the expensive failure mode here is a feed that cries wolf, because it trains
 * people to ignore the one alert that mattered. Silence is a feature and is tested.
 */
final class AnomalyScannerTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-16 09:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function brand(): Brand
    {
        $b = Brand::factory()->create(['base_currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        DB::table('platform_connections')->insert([
            'brand_id' => $b->id, 'platform' => 'meta', 'external_id' => 'acct', 'credentials' => '{}',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $b;
    }

    /** @param array<string, mixed> $cols */
    private function metaDay(Brand $b, string $date, array $cols): void
    {
        DB::table('daily_metrics')->insert(array_merge([
            'brand_id' => $b->id, 'platform' => 'meta', 'date' => $date,
            'currency' => 'USD', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ], $cols));
    }

    /** 28 baseline days ending the day before DAY. */
    private function baseline(Brand $b, array $cols): void
    {
        $d = CarbonImmutable::parse(self::DAY);
        for ($i = 1; $i <= 28; $i++) {
            $this->metaDay($b, $d->subDays($i)->toDateString(), $cols);
        }
    }

    /** @return array<int, string> the kinds that fired */
    private function scan(Brand $b): array
    {
        $found = app(AnomalyScanner::class)->scan($b->fresh(), CarbonImmutable::parse(self::DAY));

        return array_column($found, 'kind');
    }

    public function test_cpm_spike_fires_above_threshold_and_is_silent_below(): void
    {
        // Baseline CPM = 100/10000 × 1000 = $10.
        $quiet = $this->brand();
        $this->baseline($quiet, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        // Today CPM = $13 → +30%, under the 40% threshold → SILENCE.
        $this->metaDay($quiet, self::DAY, ['spend' => 130, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        $this->assertNotContains('cpm_spike', $this->scan($quiet));

        $loud = $this->brand();
        $this->baseline($loud, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        // Today CPM = $15 → +50%, over 40% → FIRES.
        $this->metaDay($loud, self::DAY, ['spend' => 150, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 450]);
        $this->assertContains('cpm_spike', $this->scan($loud));

        $ev = Anomaly::where('brand_id', $loud->id)->where('kind', 'cpm_spike')->first()->evidence;
        // The evidence lets a human re-derive the alert by hand.
        $this->assertEqualsWithDelta(15.0, $ev['actual'], 0.01);
        $this->assertEqualsWithDelta(10.0, $ev['median28d'], 0.01);
        $this->assertEqualsWithDelta(50.0, $ev['deltaPct'], 0.1);
        $this->assertSame(40, (int) $ev['thresholdPct']);
    }

    public function test_roas_drop_fires_only_past_the_threshold(): void
    {
        // Baseline ROAS = 300/100 = 3.0×.
        $quiet = $this->brand();
        $this->baseline($quiet, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        // Today 2.4× → −20%, under the 35% drop threshold → silent.
        $this->metaDay($quiet, self::DAY, ['spend' => 100, 'impressions' => 10000, 'conversions' => 4, 'conversion_value' => 240]);
        $this->assertNotContains('roas_drop', $this->scan($quiet));

        $loud = $this->brand();
        $this->baseline($loud, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        // Today 1.5× → −50% → fires.
        $this->metaDay($loud, self::DAY, ['spend' => 100, 'impressions' => 10000, 'conversions' => 2, 'conversion_value' => 150]);
        $this->assertContains('roas_drop', $this->scan($loud));
    }

    public function test_zero_delivery_fires_when_a_spending_platform_goes_silent(): void
    {
        $b = $this->brand();
        $this->baseline($b, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        // Today: the platform delivered nothing at all.
        $this->metaDay($b, self::DAY, ['spend' => 0, 'impressions' => 0, 'conversions' => 0, 'conversion_value' => 0]);

        $kinds = $this->scan($b);
        $this->assertContains('zero_delivery', $kinds);

        $a = Anomaly::where('brand_id', $b->id)->where('kind', 'zero_delivery')->first();
        $this->assertSame('critical', $a->severity);   // a stopped platform is the one you cannot miss
    }

    public function test_no_baseline_means_no_alerts_at_all(): void
    {
        // 5 days of history — below min_days (14). A confident alert computed from five
        // days of noise is a wrong number, so the scanner must stay completely silent.
        $b = $this->brand();
        $d = CarbonImmutable::parse(self::DAY);
        for ($i = 1; $i <= 5; $i++) {
            $this->metaDay($b, $d->subDays($i)->toDateString(), ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        }
        $this->metaDay($b, self::DAY, ['spend' => 9999, 'impressions' => 100, 'conversions' => 0, 'conversion_value' => 0]);

        $this->assertSame([], $this->scan($b));
        $this->assertSame(0, Anomaly::where('brand_id', $b->id)->count());
    }

    public function test_stockout_on_ads_fires_when_spend_chases_a_product_with_no_stock(): void
    {
        $b = $this->brand();
        $this->baseline($b, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        $this->metaDay($b, self::DAY, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);

        DB::table('product_catalog')->insert([
            'brand_id' => $b->id, 'handle' => 'sold-out-shoe', 'title' => 'Sold Out Shoe',
            'variant_count' => 1, 'total_inventory' => 0,     // no stock
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ad_product_daily')->insert([
            'brand_id' => $b->id, 'date' => self::DAY, 'product_key' => 'sold-out-shoe',
            'spend' => 200, 'ads_count' => 1, 'currency' => 'USD', 'fx_rate_to_usd' => 1.0,
            'is_complete' => true, 'pulled_at' => now(),
        ]);

        $this->assertContains('stockout_on_ads', $this->scan($b));

        $a = Anomaly::where('brand_id', $b->id)->where('kind', 'stockout_on_ads')->first();
        $this->assertSame('sold-out-shoe', $a->subject);
        $this->assertSame('critical', $a->severity);
        $this->assertEqualsWithDelta(200.0, $a->evidence['spendUsd'], 0.01);
    }

    public function test_rescanning_a_day_updates_rather_than_duplicating(): void
    {
        $b = $this->brand();
        $this->baseline($b, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        $this->metaDay($b, self::DAY, ['spend' => 150, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 450]);

        app(AnomalyScanner::class)->scan($b->fresh(), CarbonImmutable::parse(self::DAY));
        app(AnomalyScanner::class)->scan($b->fresh(), CarbonImmutable::parse(self::DAY));

        // An alert feed that duplicates itself is an alert feed people stop reading.
        $this->assertSame(1, Anomaly::where('brand_id', $b->id)->where('kind', 'cpm_spike')->count());
    }

    public function test_dismissal_requires_a_reason(): void
    {
        $b = $this->brand();
        $this->baseline($b, ['spend' => 100, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 300]);
        $this->metaDay($b, self::DAY, ['spend' => 150, 'impressions' => 10000, 'conversions' => 5, 'conversion_value' => 450]);
        app(AnomalyScanner::class)->scan($b->fresh(), CarbonImmutable::parse(self::DAY));

        $a = Anomaly::where('brand_id', $b->id)->first();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        // No reason → rejected. The reason is the honesty record the ledger will score.
        $this->postJson("/api/brands/{$b->slug}/anomalies/{$a->id}/dismiss", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/brands/{$b->slug}/anomalies/{$a->id}/dismiss", ['reason' => 'Planned Black Friday test — expected CPM rise.'])
            ->assertOk();

        $a->refresh();
        $this->assertNotNull($a->resolved_at);
        $this->assertStringContainsString('Black Friday', $a->resolution_reason);

        // Resolved anomalies drop out of the open feed.
        $this->assertSame(0, collect($this->getJson("/api/brands/{$b->slug}/anomalies")->json('rows'))->count());
    }
}
