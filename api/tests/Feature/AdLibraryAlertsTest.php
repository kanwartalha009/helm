<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdLibraryAd;
use App\Models\AdLibraryPage;
use App\Models\User;
use App\Platforms\MetaAdLibrary\ArchiveFetcher;
use App\Platforms\MetaAdLibrary\Contracts\AdLibrarySource;
use App\Platforms\MetaAdLibrary\VendorSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Ads Library Phase 5 — competitor movement alerts (deterministic, from stored
 * corpus deltas) + the vendor-seam contract (VendorSource can't drift from the
 * official source's interface, and is OFF).
 */
final class AdLibraryAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function ad(string $pageId, string $id, string $media, string $firstSeen): void
    {
        AdLibraryAd::create([
            'ad_archive_id' => $id, 'page_id' => $pageId, 'concept_hash' => sha1($id),
            'is_active' => true, 'media_type' => $media, 'first_seen_at' => $firstSeen, 'delivery_start' => '2026-06-01',
        ]);
    }

    public function test_new_ads_spike_and_new_format_alerts_fire(): void
    {
        AdLibraryPage::create(['page_id' => 'P1', 'page_name' => 'Comp', 'niche' => 'footwear', 'status' => 'active']);
        $this->ad('P1', 'OLD', 'image', now()->subDays(30)->toDateTimeString());       // prior active
        foreach (['N1', 'N2', 'N3'] as $i) {
            $this->ad('P1', $i, 'image', now()->subDays(2)->toDateTimeString());        // 3 new this week
        }
        $this->ad('P1', 'NVID', 'video', now()->subDay()->toDateTimeString());          // first video

        Sanctum::actingAs(User::factory()->create(['role' => 'master_admin']));
        $types = array_column($this->getJson('/api/ads-library/alerts')->assertOk()->json('alerts'), 'type');

        $this->assertContains('new_ads', $types);       // 4 new this week
        $this->assertContains('variant_spike', $types); // 5 active, 4 new ≥ prior 1
        $this->assertContains('new_format', $types);    // first video, no older video
    }

    public function test_vendor_seam_contract_and_off(): void
    {
        $this->assertInstanceOf(AdLibrarySource::class, app(ArchiveFetcher::class));
        $vendor = new VendorSource();
        $this->assertInstanceOf(AdLibrarySource::class, $vendor);

        // Vendor is OFF — any call throws (can never silently activate).
        $this->expectException(RuntimeException::class);
        $vendor->byTerms('x', 'KEYWORD_UNORDERED', ['ES']);
    }
}
