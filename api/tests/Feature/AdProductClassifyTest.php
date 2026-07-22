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

    public function test_extract_url_reads_the_confirmed_creative_field_paths(): void
    {
        // Regression guard on extractUrl's field precedence (video CTA → link_data
        // → template → asset feed → top-level). effective_object_story_spec is NOT
        // read: it is not a field on the adcreative node (Meta error 100), and
        // requesting it 400s the whole batch — proven live 2026-07-22.
        $this->assertSame('https://s.com/products/a', AdProductFetcher::extractUrl([
            'object_story_spec' => ['video_data' => ['call_to_action' => ['value' => ['link' => 'https://s.com/products/a']]]],
        ]));
        $this->assertSame('https://s.com/products/b', AdProductFetcher::extractUrl([
            'object_story_spec' => ['link_data' => ['link' => 'https://s.com/products/b']],
        ]));
        $this->assertSame('https://s.com/products/c', AdProductFetcher::extractUrl([
            'asset_feed_spec' => ['link_urls' => [['website_url' => 'https://s.com/products/c']]],
        ]));
        $this->assertSame('https://s.com/products/d', AdProductFetcher::extractUrl(['link_url' => 'https://s.com/products/d']));
        // A creative with no readable link → '' (caller buckets it into __other).
        $this->assertSame('', AdProductFetcher::extractUrl(['object_story_spec' => []]));
    }
}
