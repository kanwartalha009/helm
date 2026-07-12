<?php

declare(strict_types=1);

namespace App\Platforms\MetaAdLibrary\Contracts;

/**
 * The pluggable ad-library data source (Ads Library Phase 5.2 — vendor seam).
 * OfficialApiSource (the Meta Ad Library API) is the ONLY implementation wired in.
 * A VendorSource stub documents the swap point for later — vendors add US/global
 * coverage + media urls but sit in a scraping-ToS gray zone, so it stays OFF until
 * Kanwar approves the recurring cost. Both must satisfy this contract so a swap
 * can't drift (enforced by an interface contract test).
 *
 * @return array{rows: list<array<string, mixed>>, next: ?string}
 */
interface AdLibrarySource
{
    /**
     * @param array<int, string>   $pageIds
     * @param array<int, string>   $countries
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function byPages(array $pageIds, array $countries, array $filters = [], ?string $cursor = null): array;

    /**
     * @param array<int, string>   $countries
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function byTerms(string $terms, string $searchType, array $countries, array $filters = [], ?string $cursor = null): array;
}
