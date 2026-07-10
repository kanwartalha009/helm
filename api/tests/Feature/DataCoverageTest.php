<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BackfillBrandDatasetJob;
use App\Jobs\BackfillBrandRangeJob;
use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // History rides the existing per-day fan-out job.
        $this->postJson("/api/brands/{$this->brand->slug}/backfill-dataset", ['dataset' => 'history'])
            ->assertStatus(202);
        Queue::assertPushed(BackfillBrandRangeJob::class, 1);

        $this->assertDatabaseHas('backfill_runs', [
            'brand_id' => $this->brand->id, 'dataset' => 'creatives', 'status' => 'queued',
        ]);
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
