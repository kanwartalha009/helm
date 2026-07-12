<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdLibraryAd;
use App\Models\AdLibraryPage;
use App\Platforms\MetaAdLibrary\ArchiveFetcher;
use App\Services\AdsLibrary\AdLibrarySync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Ads Library Phase 2 — AdLibrarySync upsert idempotency + the active→inactive
 * transition. ArchiveFetcher is mocked (no HTTP); this tests the storage logic.
 */
final class AdLibrarySyncTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $o */
    private function row(string $id, array $o = []): array
    {
        return array_merge([
            'ad_archive_id' => $id, 'page_id' => '123', 'page_name' => 'Nike', 'permalink' => 'https://www.facebook.com/ads/library/?id=' . $id,
            'ad_created_at' => null, 'delivery_start' => '2026-06-01', 'delivery_stop' => null, 'media_type' => null,
            'eu_total_reach' => 1000, 'target_gender' => null, 'concept_hash' => sha1('123|just do it'),
            'creative_bodies' => ['Just do it'], 'countries' => ['ES'], 'raw' => ['id' => $id],
        ], $o);
    }

    private function syncWith(array $rows): AdLibrarySync
    {
        $fetcher = Mockery::mock(ArchiveFetcher::class);
        $fetcher->shouldReceive('byPages')->andReturn(['rows' => $rows, 'next' => null]);

        return new AdLibrarySync($fetcher);
    }

    private function page(): AdLibraryPage
    {
        return AdLibraryPage::create(['page_id' => '123', 'page_name' => 'Nike', 'niche' => 'footwear', 'status' => 'active']);
    }

    public function test_upsert_is_idempotent(): void
    {
        $page = $this->page();
        $sync = $this->syncWith([$this->row('AD1')]);

        $sync->syncPage($page, ['ES'], 5);
        $sync->syncPage($page, ['ES'], 5); // rerun

        $this->assertSame(1, AdLibraryAd::where('ad_archive_id', 'AD1')->count());
        $this->assertTrue((bool) AdLibraryAd::where('ad_archive_id', 'AD1')->first()->is_active);
    }

    public function test_departed_ad_goes_inactive_with_delivery_stop(): void
    {
        $page = $this->page();

        // Run 1: page shows AD1.
        $this->syncWith([$this->row('AD1')])->syncPage($page, ['ES'], 5);
        $this->assertTrue((bool) AdLibraryAd::where('ad_archive_id', 'AD1')->first()->is_active);

        // Run 2: page shows nothing → AD1 departed.
        $this->syncWith([])->syncPage($page, ['ES'], 5);

        $ad = AdLibraryAd::where('ad_archive_id', 'AD1')->first();
        $this->assertFalse((bool) $ad->is_active);
        $this->assertNotNull($ad->delivery_stop, 'a departed ad gets a delivery_stop');
    }

    public function test_same_concept_hash_persists_for_variants(): void
    {
        $page = $this->page();
        $hash = sha1('123|just do it');
        $this->syncWith([
            $this->row('V1', ['concept_hash' => $hash]),
            $this->row('V2', ['concept_hash' => $hash]),
        ])->syncPage($page, ['ES'], 5);

        $this->assertSame(
            2,
            AdLibraryAd::where('concept_hash', $hash)->where('is_active', true)->count(),
        );
    }
}
