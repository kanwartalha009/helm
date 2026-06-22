<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke tests for the reporting engine (feature spec slice 2.0). Verify the
 * registry lists types, the Overall Performance builder returns the expected
 * payload shape, and the missing-≠-zero rule holds for an unconnected platform.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_registered_report_types(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('reports.0.key', 'overall-performance');
    }

    public function test_overall_performance_payload_and_missing_is_not_zero(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create([
            'base_currency' => 'EUR',
            'timezone'      => 'Europe/Madrid',
            'status'        => 'active',
        ]);

        // One Shopify revenue row inside the last-30-day window.
        $row = new DailyMetric();
        $row->forceFill([
            'brand_id'    => $brand->id,
            'platform'    => 'shopify',
            'date'        => now()->subDays(3)->toDateString(),
            'total_sales' => 1000,
            'net_sales'   => 900,
            'orders'      => 5,
            'currency'    => 'EUR',
            'is_complete' => true,
            'pulled_at'   => now(),
        ])->save();

        Sanctum::actingAs($user);

        $res = $this->getJson("/api/brands/{$brand->slug}/reports/overall-performance?period=last30&compare=previous")
            ->assertOk()
            ->assertJsonPath('reportType', 'overall-performance')
            ->assertJsonPath('brand.slug', $brand->slug)
            ->assertJsonStructure([
                'currency',
                'period'   => ['label', 'start', 'end'],
                'kpis'     => ['revenue' => ['value', 'previous', 'deltaPct', 'deltaAbs'], 'adSpend', 'blendedRoas', 'orders', 'aov'],
                'revenueVsSpend',
                'byPlatform',
                'branding' => ['agency_name', 'accent', 'footer_text'],
            ]);

        // Revenue is the summed Shopify total_sales.
        $this->assertEquals(1000, $res->json('kpis.revenue.value'));

        // Missing ≠ zero: no Meta connection → connected:false with null spend,
        // never spend 0.
        $meta = collect($res->json('byPlatform'))->firstWhere('platform', 'meta');
        $this->assertFalse($meta['connected']);
        $this->assertNull($meta['spend']);
    }

    public function test_unknown_report_type_is_404(): void
    {
        $user  = User::factory()->create(['role' => 'master_admin']);
        $brand = Brand::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson("/api/brands/{$brand->slug}/reports/not-a-real-report")
            ->assertNotFound();
    }
}
