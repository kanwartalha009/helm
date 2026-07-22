<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platforms\Meta\AdProductFetcher;
use PHPUnit\Framework\TestCase;

/**
 * The landing-URL → product-handle regex (spec §4 Phase 5) shared by all three
 * platform ad-product fetchers. Locale prefixes and query/fragment tails must be
 * ignored; non-product URLs resolve to the reserved buckets, never a wrong handle.
 * Pure static methods — no DB.
 */
final class AdProductClassifyTest extends TestCase
{
    public function test_product_handle_ignores_locale_and_query(): void
    {
        $this->assertSame('blue-tee', AdProductFetcher::productHandle('https://shop.com/products/blue-tee'));
        $this->assertSame('blue-tee', AdProductFetcher::productHandle('https://shop.com/en-de/products/blue-tee?variant=42'));
        $this->assertSame('red-cap', AdProductFetcher::productHandle('https://shop.com/fr/products/red-cap#reviews'));
        $this->assertSame('red-cap', AdProductFetcher::productHandle('https://shop.com/it/products/red-cap/'));
    }

    public function test_non_product_urls_return_null_handle(): void
    {
        $this->assertNull(AdProductFetcher::productHandle('https://shop.com/collections/summer'));
        $this->assertNull(AdProductFetcher::productHandle('https://shop.com/pages/about'));
        $this->assertNull(AdProductFetcher::productHandle('https://shop.com/'));
        $this->assertNull(AdProductFetcher::productHandle(''));
    }

    public function test_classify_maps_to_reserved_buckets(): void
    {
        $this->assertSame('blue-tee', AdProductFetcher::classify('https://shop.com/products/blue-tee'));
        $this->assertSame(AdProductFetcher::RESERVED_COLLECTION, AdProductFetcher::classify('https://shop.com/collections/summer'));
        $this->assertSame(AdProductFetcher::RESERVED_OTHER, AdProductFetcher::classify('https://shop.com/'));
        $this->assertSame(AdProductFetcher::RESERVED_OTHER, AdProductFetcher::classify(''));
    }

    // ── H1 fix (Kanwar, 2026-07-22 — Bruna amboise incident) ──────────────────
    // Partnership / whitelisted / dark-post ads run from a creator's page, so
    // their OWN object_story_spec is empty and the link lives in
    // effective_object_story_spec. extractUrl must fall through to it; before the
    // fix these ads returned '' → __other, under-counting the product.

    public function test_normal_ad_resolves_via_object_story_spec_unchanged(): void
    {
        // A plain video ad with its own object_story_spec — the pre-fix path.
        $creative = [
            'object_story_spec' => [
                'video_data' => ['call_to_action' => ['value' => ['link' => 'https://bruna.com/products/amboise-stud']]],
            ],
        ];
        $this->assertSame('https://bruna.com/products/amboise-stud', AdProductFetcher::extractUrl($creative));
        $this->assertSame('amboise-stud', AdProductFetcher::classify(AdProductFetcher::extractUrl($creative)));
    }

    public function test_partnership_ad_resolves_via_effective_object_story_spec(): void
    {
        // The incident shape: object_story_spec EMPTY (creator-owned post), the
        // real landing link only in effective_object_story_spec.link_data.
        $partnership = [
            'object_story_spec'           => [],
            'effective_object_story_spec' => [
                'link_data' => ['link' => 'https://bruna.com/de/products/amboise-stud?utm=paid'],
            ],
        ];
        $url = AdProductFetcher::extractUrl($partnership);
        $this->assertSame('https://bruna.com/de/products/amboise-stud?utm=paid', $url);
        // Locale prefix stripped, query dropped → the product row, NOT __other.
        $this->assertSame('amboise-stud', AdProductFetcher::classify($url));
    }

    public function test_object_story_spec_still_wins_over_the_effective_twin(): void
    {
        // When BOTH are present the ad's own spec is authoritative (no regression).
        $creative = [
            'object_story_spec'           => ['link_data' => ['link' => 'https://bruna.com/products/real-target']],
            'effective_object_story_spec' => ['link_data' => ['link' => 'https://bruna.com/products/other']],
        ];
        $this->assertSame('https://bruna.com/products/real-target', AdProductFetcher::extractUrl($creative));
    }

    public function test_partnership_video_cta_link_in_effective_spec(): void
    {
        // Partnership VIDEO ad — link under effective_object_story_spec.video_data.
        $creative = [
            'effective_object_story_spec' => [
                'video_data' => ['call_to_action' => ['value' => ['link' => 'https://bruna.com/products/amboise-stud']]],
            ],
        ];
        $this->assertSame('amboise-stud', AdProductFetcher::classify(AdProductFetcher::extractUrl($creative)));
    }

    public function test_genuinely_linkless_creative_still_falls_to_other(): void
    {
        // A creative with neither spec populated stays unattributed — but the
        // caller counts it in __other (never dropped); here we just prove '' → __other.
        $this->assertSame('', AdProductFetcher::extractUrl(['object_story_spec' => [], 'effective_object_story_spec' => []]));
        $this->assertSame(AdProductFetcher::RESERVED_OTHER, AdProductFetcher::classify(''));
    }
}
