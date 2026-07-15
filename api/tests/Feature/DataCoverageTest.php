<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BackfillBrandDatasetJob;
use App\Models\BackfillRun;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Onboarding data-coverage detection + manual backfill triggers (2026-07-10). */
final class DataCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->brand = Brand::factory()->create(['timezone' => 'UTC', 'status' => 'active']);
        foreach (['shopify', 'meta'] as $i => $platform) {
            (new PlatformConnection())->forceFill([
                'brand_id' => $this->brand->id, 'platform' => $platform,
                'external_id' => "acc-{$i}", 'status' => 'active',
                'credentials' => ['k' => 'v'],
            ])->save();
        }
    }

    public function test_fresh_brand_reports_gaps_everywhere_relevant(): void
    {
        $res = $this->getJson("/api/brands/{$this->brand->slug}/data-coverage")->assertOk();

        $byKey = collect($res->json('datasets'))->keyBy('key');
        $this->assertTrue($res->json('anyGap'));
        $this->assertTrue($byKey['history']['needsBackfill']);
        $this->assertTrue($byKey['campaigns']['needsBackfill']); // meta connected
        $this->assertTrue($byKey['creatives']['needsBackfill']); // meta connected
        $this->assertTrue($byKey['commerce']['needsBackfill']);  // shopify connected
    }

    public function test_full_history_clears_the_gap(): void
    {
        $start = now('UTC')->subMonths(13)->toDateString();
        foreach (['shopify', 'meta'] as $platform) {
            DB::table('daily_metrics')->insert([
                'brand_id' => $this->brand->id, 'platform' => $platform, 'date' => $start,
                'currency' => 'EUR', 'fx_rate_to_usd' => 1, 'is_complete' => true, 'pulled_at' => now(),
            ]);
        }

        $byKey = collect($this->getJson("/api/brands/{$this->brand->slug}/data-coverage")->json('datasets'))->keyBy('key');
        $this->assertFalse($byKey['history']['needsBackfill']);
        $this->assertTrue($byKey['campaigns']['needsBackfill'], 'campaign table still empty');
    }

    public function test_trigger_dispatches_and_blocks_duplicates(): void
    {
        Queue::fake();

        $this->postJson("/api/brands/{$this->brand->slug}/backfill-dataset", ['dataset' => 'creatives'])
            ->assertStatus(202);
        Queue::assertPushed(BackfillBrandDatasetJob::class, 1);

        // Same dataset again while queued → 409, no second job.
        $this->postJson("/api/brands/{$this->brand->slug}/backfill-dataset", ['dataset' => 'creatives'])
            ->assertStatus(409);
        Queue::assertPushed(BackfillBrandDatasetJob::class, 1);

        // History is a single tracked ranged job too (2026-07-10) — never a
        // per-day fan-out.
        $this->postJson("/api/brands/{$this->brand->slug}/backfill-dataset", ['dataset' => 'history'])
            ->assertStatus(202);
        Queue::assertPushed(BackfillBrandDatasetJob::class, 2);

        $this->assertDatabaseHas('backfill_runs', [
            'brand_id' => $this->brand->id, 'dataset' => 'creatives', 'status' => 'queued',
        ]);
        $this->assertDatabaseHas('backfill_runs', [
            'brand_id' => $this->brand->id, 'dataset' => 'history', 'status' => 'queued',
        ]);
    }

    public function test_all_dataset_is_one_job_and_blocks_everything(): void
    {
        Queue::fake();

        // 'all' → exactly ONE job covering every dataset + platform.
        $this->postJson("/api/brands/{$this->brand->slug}/backfill-dataset", ['dataset' => 'all'])
            ->assertStatus(202);
        Queue::assertPushed(BackfillBrandDatasetJob::class, 1);

        // While 'all' is active, every other dataset (and another 'all') is 409.
        foreach (['all', 'history', 'creatives', 'commerce'] as $dataset) {
            $this->postJson("/api/brands/{$this->brand->slug}/backfill-dataset", ['dataset' => $dataset])
                ->assertStatus(409);
        }
        Queue::assertPushed(BackfillBrandDatasetJob::class, 1);

        // Coverage reports every relevant dataset as running.
        $byKey = collect($this->getJson("/api/brands/{$this->brand->slug}/data-coverage")->json('datasets'))->keyBy('key');
        $this->assertTrue($byKey['history']['running']);
        $this->assertTrue($byKey['creatives']['running']);
        $this->assertTrue($byKey['commerce']['running']);
    }

    public function test_campaigns_backfill_includes_ad_products_for_meta_brand(): void
    {
        // Product-attributed Meta spend (ad_product_daily) rides the campaigns
        // dataset (2026-07-10) — it powers the Inventory Intelligence report,
        // so a campaigns/all backfill must run meta:backfill-ad-products too
        // for a meta-connected brand.
        $run = BackfillRun::create([
            'brand_id'     => $this->brand->id,
            'dataset'      => 'campaigns',
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
        // the same way Laravel's worker does, via app()->call().
        app()->call([new BackfillBrandDatasetJob($this->brand, 'campaigns', $run->id), 'handle']);

        $this->assertSame('done', $run->refresh()->status);
        $this->assertContains(
            ['ads:backfill-campaigns', ['brand' => (string) $this->brand->slug, '--since' => $since]],
            $calls,
        );
        $this->assertContains(
            ['meta:backfill-ad-products', ['brand' => (string) $this->brand->slug, '--since' => $since]],
            $calls,
        );
    }

    public function test_trigger_is_admin_manager_only(): void
    {
        $tm = User::factory()->create(['role' => 'team_member']);
        $this->brand->users()->attach($tm->id);
        Sanctum::actingAs($tm);

        $this->postJson("/api/brands/{$this->brand->slug}/backfill-dataset", ['dataset' => 'commerce'])
            ->assertForbidden();
        // Coverage view itself is fine for any brand-visible user.
        $this->getJson("/api/brands/{$this->brand->slug}/data-coverage")->assertOk();
    }
}
