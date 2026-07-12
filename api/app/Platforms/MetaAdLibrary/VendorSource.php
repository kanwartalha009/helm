<?php

declare(strict_types=1);

namespace App\Platforms\MetaAdLibrary;

use App\Platforms\MetaAdLibrary\Contracts\AdLibrarySource;
use RuntimeException;

/**
 * Documented vendor-source STUB (Ads Library Phase 5.2) — OFF until Kanwar
 * approves recurring spend. A vendor adds US/global commercial ads + direct media
 * urls (sometimes spend/impression estimates), but scrapes the website's internal
 * GraphQL — a ToS gray zone. Researched options + published prices (bring measured
 * coverage gaps, not vibes, to the decision):
 *   - ScrapeCreators  $0.99–1.88 / 1k requests (credits never expire)
 *   - Apify actors    $0.50–5.80 / 1k ads
 *   - SearchAPI.io    $1–4 / 1k searches
 *
 * Implements the same AdLibrarySource contract as the official fetcher so the swap
 * is a one-line binding change — but every method throws until a vendor is chosen,
 * so it can never silently activate.
 */
class VendorSource implements AdLibrarySource
{
    public function byPages(array $pageIds, array $countries, array $filters = [], ?string $cursor = null): array
    {
        throw new RuntimeException('No ad-library vendor is configured. The official Meta Ad Library API is the active source.');
    }

    public function byTerms(string $terms, string $searchType, array $countries, array $filters = [], ?string $cursor = null): array
    {
        throw new RuntimeException('No ad-library vendor is configured. The official Meta Ad Library API is the active source.');
    }
}
