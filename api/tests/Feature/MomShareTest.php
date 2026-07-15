<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ReportCommentary;
use App\Models\ReportShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M5 addendum (Kanwar, 2026-07-15 — "complete the full mom report", public
 * share links) — MomShareController: create -> token, public shell (the
 * snapshotted section manifest), public per-section rebuild. See the
 * controller's own docblock for why this ISN'T v1's ReportController flow
 * reused (mom is section-streamed, v1's is a single monolithic build()).
 */
class MomShareTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Madrid';

    private function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfMonth()->subMonth();
    }

    private function makeBrand(): Brand
    {
        return Brand::factory()->create(['base_currency' => 'EUR', 'timezone' => self::TZ, 'status' => 'active']);
    }

    private function actingMasterAdmin(): User
    {
        $user = User::factory()->create(['role' => 'master_admin']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function seedDaily(int $brandId, string $platform, string $date, array $cols): void
    {
        DB::table('daily_metrics')->insert(array_merge([
            'brand_id' => $brandId, 'platform' => $platform, 'date' => $date,
            'currency' => 'EUR', 'fx_rate_to_usd' => 1.0, 'is_complete' => true, 'pulled_at' => now(),
        ], $cols));
    }

    public function test_create_share_snapshots_only_enabled_and_built_sections(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();

        $res = $this->postJson("/api/brands/{$brand->slug}/reports/mom/shares", [
            'filters' => ['month' => $month->format('Y-m'), 'compare' => 'previous'],
        ])->assertCreated();

        $this->assertNotEmpty($res->json('token'));
        $this->assertStringStartsWith('/mom/r/', $res->json('url'));

        $share = ReportShare::query()->where('token', $res->json('token'))->firstOrFail();
        $this->assertSame('mom', $share->report_type);
        $keys = collect($share->content['sections'])->pluck('key');
        // S-EX/S1 are enabled + built by the code default -> in the snapshot.
        $this->assertTrue($keys->contains('S-EX'));
        $this->assertTrue($keys->contains('S1'));
        // Every snapshotted key must be one MomSectionRegistry can actually build —
        // no section that would 404/degrade dishonestly on a public view.
        $this->assertTrue($keys->every(fn ($k) => in_array($k, (new \App\Reports\Mom\MomSectionRegistry(app()))->builtKeys(), true)));
    }

    public function test_public_shell_reads_the_snapshot_not_a_live_relayout(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();

        $token = $this->postJson("/api/brands/{$brand->slug}/reports/mom/shares", [
            'filters' => ['month' => $month->format('Y-m'), 'compare' => 'previous'],
        ])->assertCreated()->json('token');

        // No auth at all on the public route.
        $res = $this->getJson("/api/mom/r/{$token}")->assertOk();
        $this->assertSame('mom', $res->json('reportType'));
        $this->assertSame($brand->name, $res->json('brand.name'));
        $this->assertTrue($res->json('shared'));
        $this->assertNotEmpty($res->json('sections'));
    }

    public function test_public_section_rebuilds_live_pinned_to_the_snapshotted_filters(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();
        $this->seedDaily($brand->id, 'shopify', $month->addDays(2)->toDateString(), ['total_sales' => 1000, 'refunds_amount' => 0, 'orders' => 10]);
        $this->seedDaily($brand->id, 'meta', $month->addDays(2)->toDateString(), ['spend' => 200]);

        $token = $this->postJson("/api/brands/{$brand->slug}/reports/mom/shares", [
            'filters' => ['month' => $month->format('Y-m'), 'compare' => 'previous'],
        ])->assertCreated()->json('token');

        $res = $this->getJson("/api/mom/r/{$token}/sections/S-EX")->assertOk();
        $this->assertSame('ok', $res->json('status'));
        $this->assertEquals(1000.0, $res->json('tiles.revenue.value'));
        // No internal-only CTA on a public view, even if it were needs_source.
        $this->assertArrayNotHasKey('backfillDataset', $res->json());
    }

    public function test_public_section_carries_read_only_commentary(): void
    {
        $user  = $this->actingMasterAdmin();
        $brand = $this->makeBrand();
        $month = $this->monthStart();

        ReportCommentary::query()->create([
            'brand_id' => $brand->id, 'report_type' => 'mom', 'month' => $month->format('Y-m'),
            'section_key' => 'S-EX', 'commentary' => 'Strong month.', 'todo' => [['text' => 'Push Germany', 'done' => false]],
            'updated_by' => $user->id,
        ]);

        $token = $this->postJson("/api/brands/{$brand->slug}/reports/mom/shares", [
            'filters' => ['month' => $month->format('Y-m'), 'compare' => 'previous'],
        ])->assertCreated()->json('token');

        $res = $this->getJson("/api/mom/r/{$token}/sections/S-EX")->assertOk();
        $this->assertSame('Strong month.', $res->json('commentary'));
        $this->assertSame('Push Germany', $res->json('todo.0.text'));
    }

    public function test_public_routes_404_honestly_for_a_key_not_in_the_snapshot_or_a_bad_token(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $token = $this->postJson("/api/brands/{$brand->slug}/reports/mom/shares", ['filters' => []])
            ->assertCreated()->json('token');

        // S16's own DATASET_MAP/registry entry exists, but a share only ever
        // snapshots ENABLED sections at creation time — a disabled/unbuilt key
        // for THIS share still 404s honestly rather than leaking live data
        // never promised to this link.
        $this->getJson('/api/mom/r/' . $token . '/sections/S999')->assertNotFound();
        $this->getJson('/api/mom/r/not-a-real-token')->assertNotFound();
        $this->getJson('/api/mom/r/not-a-real-token/sections/S1')->assertNotFound();
    }

    public function test_expired_share_404s(): void
    {
        $this->actingMasterAdmin();
        $brand = $this->makeBrand();

        $token = $this->postJson("/api/brands/{$brand->slug}/reports/mom/shares", [
            'filters' => [], 'expiresInDays' => 1,
        ])->assertCreated()->json('token');

        ReportShare::query()->where('token', $token)->update(['expires_at' => now()->subDay()]);

        $this->getJson("/api/mom/r/{$token}")->assertNotFound();
    }
}
