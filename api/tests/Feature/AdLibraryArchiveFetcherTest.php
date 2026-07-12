<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platforms\MetaAdLibrary\ArchiveFetcher;
use App\Services\PlatformCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ads Library Phase 2 — ArchiveFetcher parsing + the token-safety guarantee.
 * Missing EU fields map to null (never 0); the token-bearing ad_snapshot_url is
 * NEVER surfaced (only the public permalink); the concept-hash groups variants.
 */
final class AdLibraryArchiveFetcherTest extends TestCase
{
    use RefreshDatabase;

    private function fakeArchive(array $body): void
    {
        app(PlatformCredentialService::class)->set('meta_adlib', 'access_token', 'SECRET-TOKEN');
        Http::fake(['graph.facebook.com/*' => Http::response($body, 200)]);
    }

    public function test_maps_rows_missing_reach_stays_null_and_permalink_is_token_free(): void
    {
        $this->fakeArchive([
            'data' => [
                [
                    'id' => 'AD1', 'page_id' => '123', 'page_name' => 'Nike',
                    'ad_delivery_start_time' => '2026-06-01', 'ad_creative_bodies' => ['Just do it'],
                    'eu_total_reach' => 50000,
                    'ad_snapshot_url' => 'https://www.facebook.com/ads/archive/render_ad/?id=AD1&access_token=SECRET-TOKEN',
                ],
                [
                    'id' => 'AD2', 'page_id' => '123', 'ad_creative_bodies' => ['Just do it'], // no eu_total_reach
                ],
            ],
            'paging' => ['cursors' => ['after' => 'CURSOR2']],
        ]);

        $res = app(ArchiveFetcher::class)->byPages(['123'], ['ES']);

        $this->assertCount(2, $res['rows']);
        $this->assertSame('CURSOR2', $res['next']);

        $ad1 = $res['rows'][0];
        $this->assertSame('https://www.facebook.com/ads/library/?id=AD1', $ad1['permalink']);
        $this->assertSame(50000, $ad1['eu_total_reach']);
        $this->assertArrayNotHasKey('ad_snapshot_url', $ad1['raw']); // stripped

        $this->assertNull($res['rows'][1]['eu_total_reach']); // missing ≠ 0

        // Same page + same body → one concept.
        $this->assertSame($ad1['concept_hash'], $res['rows'][1]['concept_hash']);

        // The token appears NOWHERE in the fetched rows (a leak here is a security bug).
        $json = json_encode($res['rows']);
        $this->assertStringNotContainsString('SECRET-TOKEN', $json);
        $this->assertStringNotContainsString('access_token', $json);
    }

    public function test_textless_ads_do_not_collapse_into_one_concept(): void
    {
        $this->fakeArchive([
            'data' => [
                ['id' => 'IMG1', 'page_id' => '9', 'ad_creative_bodies' => []], // no text
                ['id' => 'IMG2', 'page_id' => '9', 'ad_creative_bodies' => []], // no text
            ],
        ]);

        $res = app(ArchiveFetcher::class)->byPages(['9'], ['ES']);

        // Fallback chain ends at the ad id → each is its own concept.
        $this->assertNotSame($res['rows'][0]['concept_hash'], $res['rows'][1]['concept_hash']);
    }
}
