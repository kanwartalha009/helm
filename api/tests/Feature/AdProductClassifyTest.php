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
}
