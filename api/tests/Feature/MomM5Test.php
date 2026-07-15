<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BackfillBrandDatasetJob;
use App\Models\BackfillRun;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M5 (monthly-report-v2-mom.md §M5) — "No-empty-fields enforcement +
 * performance": the new 'breakdowns' backfill dataset (powers S13-S16's
 * gap-filling CTA), MomSectionController attaching a `backfillDataset` hint
 * on 'needs_source' responses, and a query-count/payload-size regression
 * guard on a heavy-brand fixture — the same honest proxy M0's own regression
 * test uses for "each section stays bounded, never a monolith" (this sandbox
 * cannot measure real wall-clock time against production Nude Project, same
 * constraint M0's test docblock states).
 */
final class MomM5Test extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfMonth()->subMonth();
    }

    private function makeBrand(bool $withMeta = true): Brand
    {
        $brand = Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);
        // external_id is unique per (platform, external_id) across ALL brands
        // (not brand-scoped) — key off the brand's own id/slug so this helper
        // is safe to call more than once per test.
        foreach ($withMeta ? ['shopify', 'meta'] : ['shopify'] as $platform) {
            (new PlatformConnection())->forceFill([
                'brand_id' => $brand->id, 'platform' => $platform,
                'external_id' => "acc-{$brand->id}-{$platform}", 'status' => 'active', 'credentials' => ['k' => 'v'],
            ])->save();
        }

        return $brand;
    }

    private function actingMasterAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
    }

    public function test_breakdowns_dataset_appears_in_coverage_only_for_meta_connected_brands(): void
    {
        $this->actingMasterAdmin();
        $meta = $this->makeBrand(withMeta: true);
        $noMeta = $this->makeBrand(withMeta: false);

        $byKeyMeta = collect($this->getJson("/api/brands/{$meta->slug}/data-coverage")->assertOk()->json('datasets'))->keyBy('key');
        $this->assertTrue($byKeyMeta['breakdowns']['relevant']);

        $byKeyNoMeta = collect($this->getJson("/api/brands/{$noMeta->slug}/data-coverage")->assertOk()->json('datasets'))->keyBy('key');
        $this->assertFalse($byKeyNoMeta['breakdowns']['relevant']);
    }

    public function test_breakdowns_dataset_is_a_valid_backfill_trigger_and_dispatches(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        Queue::fake();

        $this->postJson("/api/brands/{$brand->slug}/backfill-dataset", ['dataset' => 'breakdowns'])
            ->assertStatus(202)
            ->assertJsonPath('dataset', 'breakdowns');
        Queue::assertPushed(BackfillBrandDatasetJob::class, 1);
    }

    public function test_breakdowns_job_calls_meta_backfill_breakdown_with_type_all(): void
    {
        $brand = $this->makeBrand();
        $run = BackfillRun::create([
            'brand_id'     => $brand->id,
            'dataset'      => 'breakdowns',
            'status'       => 'queued',
            'window_start' => now('UTC')->subMonths(12)->toDateString(),
        ]);
        $since = $run->window_start->toDateString();

        $calls = [];
        Artisan::shouldReceive('call')->andReturnUsing(function (string $cmd, array $args) use (&$calls): int {
            $calls[] = [$cmd, $args];

            return 0;
        });
        Artisan::shouldReceive('output')->andReturn('ok');

        // handle() takes a container-injected PlatformCredentialService — a
        // direct ->handle() call (no queue worker in between) must resolve it
        // the same way Laravel's worker does, via app()->call() (same fix as
        // DataCoverageTest's own pre-existing use of this pattern).
        app()->call([new BackfillBrandDatasetJob($brand, 'breakdowns', $run->id), 'handle']);

        $this->assertSame('done', $run->refresh()->status);
        $this->assertContains(
            ['meta:backfill-breakdown', ['brand' => (string) $brand->slug, '--since' => $since, '--type' => 'all']],
            $calls,
        );
    }

    public function test_needs_source_section_carries_its_backfill_dataset_hint(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        // S18 (Klaviyo) reads email_daily_metrics. UPDATED (end-to-end
        // completion, 2026-07-15): S18 only reaches needs_source (and carries
        // the backfill hint) when Klaviyo IS connected but unsynced — an
        // unconnected brand is hidden instead. Connect a key, then nothing
        // seeded → needs_source + 'email' hint.
        app(\App\Services\PlatformCredentialService::class)->set('klaviyo', 'private_key', 'pk_test', brandId: (int) $brand->id);
        $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S18?month={$this->monthStart()->format('Y-m')}")
            ->assertOk();
        $this->assertSame('needs_source', $res->json('status'));
        $this->assertSame('email', $res->json('backfillDataset'));

        // S13 (audience mix) reads meta_breakdown_daily → 'breakdowns'.
        $res13 = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/S13?month={$this->monthStart()->format('Y-m')}")
            ->assertOk();
        $this->assertSame('needs_source', $res13->json('status'));
        $this->assertSame('breakdowns', $res13->json('backfillDataset'));
    }

    /**
     * Heavy-brand regression guard — mirrors MonthlyReportTest's own
     * "query_count_and_payload_stay_bounded" test (M0's precedent for proving
     * "never a monolith" without live production timing). Seeds 24 months of
     * daily_metrics (S1's full two-year table) + 20 countries x 2 months of
     * commerce+meta-country-spend (S4/S5/S6's join) + 20 products/6 categories
     * (S7/S8) and asserts EACH section endpoint — hit independently, exactly
     * as the SPA does — stays under generous query-count/payload ceilings.
     * These are order-of-magnitude backstops (same caveat M0's test states),
     * not a tightly measured baseline; Kanwar should re-run for real numbers.
     */
    public function test_heavy_brand_each_section_endpoint_stays_bounded(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();

        // 24 months of shopify+meta+google+tiktok daily rows for S1's full
        // current-year + prior-year matrix.
        for ($i = 0; $i < 24; $i++) {
            $d = $month->subMonths($i);
            DB::table('daily_metrics')->insert([
                'brand_id' => $brand->id, 'platform' => 'shopify', 'date' => $d->toDateString(),
                'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                'total_sales' => 1000, 'refunds_amount' => 20, 'orders' => 15,
            ]);
            foreach (['meta', 'google', 'tiktok'] as $platform) {
                DB::table('daily_metrics')->insert([
                    'brand_id' => $brand->id, 'platform' => $platform, 'date' => $d->addDays(1)->toDateString(),
                    'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                    'spend' => 100,
                ]);
            }
        }

        $countries = ['Spain', 'France', 'Germany', 'Italy', 'Portugal', 'Netherlands', 'Belgium', 'Poland',
            'Austria', 'Sweden', 'Denmark', 'Norway', 'Finland', 'Ireland', 'Greece', 'Switzerland',
            'United Kingdom', 'United States', 'Canada', 'Mexico'];
        $isoByName = ['Spain' => 'ES', 'France' => 'FR', 'Germany' => 'DE', 'Italy' => 'IT', 'Portugal' => 'PT',
            'Netherlands' => 'NL', 'Belgium' => 'BE', 'Poland' => 'PL', 'Austria' => 'AT', 'Sweden' => 'SE',
            'Denmark' => 'DK', 'Norway' => 'NO', 'Finland' => 'FI', 'Ireland' => 'IE', 'Greece' => 'GR',
            'Switzerland' => 'CH', 'United Kingdom' => 'GB', 'United States' => 'US', 'Canada' => 'CA', 'Mexico' => 'MX'];

        $commerceRows = [];
        $breakdownRows = [];
        foreach ([$month, $month->subMonth()] as $ms) {
            $date = $ms->addDays(2)->toDateString();
            foreach ($countries as $i => $country) {
                $commerceRows[] = [
                    'brand_id' => $brand->id, 'date' => $date, 'dimension_type' => 'country',
                    'dimension_key' => $country, 'dimension_label' => $country,
                    'orders' => 5, 'total_sales' => 200 + $i, 'refunds_amount' => 0,
                    'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                ];
                $breakdownRows[] = [
                    'brand_id' => $brand->id, 'platform' => 'meta', 'date' => $date,
                    'breakdown_type' => 'country', 'segment_key' => $isoByName[$country], 'segment_label' => $country,
                    'spend' => 50 + $i, 'impressions' => 1000, 'clicks' => 20,
                    'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                ];
            }
            for ($p = 0; $p < 20; $p++) {
                $commerceRows[] = [
                    'brand_id' => $brand->id, 'date' => $date, 'dimension_type' => 'product',
                    'dimension_key' => "Product {$p}", 'dimension_label' => "Product {$p}",
                    'orders' => 2, 'total_sales' => 100 + $p, 'refunds_amount' => 0,
                    'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                ];
            }
            for ($c = 0; $c < 6; $c++) {
                $commerceRows[] = [
                    'brand_id' => $brand->id, 'date' => $date, 'dimension_type' => 'category',
                    'dimension_key' => "Category {$c}", 'dimension_label' => "Category {$c}",
                    'orders' => 8, 'total_sales' => 400 + $c, 'refunds_amount' => 0,
                    'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
                ];
            }
        }
        DB::table('commerce_daily_metrics')->insert($commerceRows);
        DB::table('meta_breakdown_daily')->insert($breakdownRows);

        $monthKey = $month->format('Y-m');
        $sections = ['S1', 'S4', 'S5', 'S6', 'S7', 'S8'];
        foreach ($sections as $key) {
            DB::enableQueryLog();
            $res = $this->getJson("/api/brands/{$brand->slug}/reports/mom/sections/{$key}?month={$monthKey}")->assertOk();
            $queryCount = count(DB::getQueryLog());
            DB::flushQueryLog();

            $this->assertSame('ok', $res->json('status'), "{$key} should be 'ok' on this fixture, got: " . $res->json('status'));
            $this->assertLessThan(
                60,
                $queryCount,
                "{$key}: query count ({$queryCount}) for 20 countries/products suggests a per-row loop, not GROUP BY aggregation."
            );

            $payloadBytes = strlen($res->getContent());
            $this->assertLessThan(
                300000,
                $payloadBytes,
                "{$key}: payload ({$payloadBytes} bytes) suggests output isn't capped at top-N."
            );
        }
    }
}
