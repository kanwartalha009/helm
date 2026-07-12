<?php

declare(strict_types=1);

namespace App\Platforms\MetaAdLibrary;

use App\Platforms\MetaAdLibrary\Contracts\AdLibrarySource;

/**
 * Turns raw `ads_archive` responses into typed corpus rows (Ads Library Phase 2).
 * NEVER surfaces the token-bearing `ad_snapshot_url` — it requests only token-free
 * fields and builds the public permalink from the ad id, and defensively strips
 * any snapshot url from the stored raw node. Missing EU fields map to null, never
 * 0 (guardrail: missing ≠ zero) — commercial ads expose no spend/impressions, so
 * those never appear here at all.
 *
 * All external HTTP is delegated to AdLibraryClient (guardrail).
 */
class ArchiveFetcher implements AdLibrarySource
{
    /** Token-free fields — deliberately NOT requesting ad_snapshot_url. */
    private const FIELDS = 'id,page_id,page_name,ad_creation_time,ad_delivery_start_time,'
        . 'ad_delivery_stop_time,ad_creative_bodies,ad_creative_link_titles,ad_creative_link_captions,'
        . 'ad_creative_link_descriptions,languages,publisher_platforms,eu_total_reach,'
        . 'age_country_gender_reach_breakdown,target_ages,target_gender,target_locations,beneficiary_payers';

    public function __construct(private readonly AdLibraryClient $client) {}

    /**
     * One page of ads for up to 10 page ids in one country set.
     *
     * @param array<int, string>  $pageIds   ≤10 (API hard cap)
     * @param array<int, string>  $countries e.g. ['ES']
     * @param array<string, mixed> $filters   media_type, ad_active_status, publisher_platforms, languages, delivery dates
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function byPages(array $pageIds, array $countries, array $filters = [], ?string $cursor = null): array
    {
        $params = [
            'search_page_ids' => json_encode(array_slice(array_values($pageIds), 0, 10)),
        ];

        return $this->fetch($params, $countries, $filters, $cursor);
    }

    /**
     * One page of ads for a keyword/phrase search (≤100 chars).
     *
     * @param array<int, string>  $countries
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    public function byTerms(string $terms, string $searchType, array $countries, array $filters = [], ?string $cursor = null): array
    {
        $params = [
            'search_terms' => mb_substr($terms, 0, 100),
            'search_type'  => in_array($searchType, ['KEYWORD_UNORDERED', 'KEYWORD_EXACT_PHRASE'], true) ? $searchType : 'KEYWORD_UNORDERED',
        ];

        return $this->fetch($params, $countries, $filters, $cursor);
    }

    /**
     * @param array<string, mixed>  $base
     * @param array<int, string>    $countries
     * @param array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, next: ?string}
     */
    private function fetch(array $base, array $countries, array $filters, ?string $cursor): array
    {
        $params = array_merge($base, [
            'ad_reached_countries' => json_encode(array_values($countries)), // REQUIRED
            'ad_type'              => 'ALL',
            'ad_active_status'     => (string) ($filters['ad_active_status'] ?? 'ACTIVE'),
            'fields'               => self::FIELDS,
            'limit'                => (int) ($filters['limit'] ?? 100),
        ]);
        foreach (['media_type', 'publisher_platforms', 'languages', 'ad_delivery_date_min', 'ad_delivery_date_max'] as $k) {
            if (! empty($filters[$k])) {
                $params[$k] = is_array($filters[$k]) ? json_encode($filters[$k]) : $filters[$k];
            }
        }
        if ($cursor !== null && $cursor !== '') {
            $params['after'] = $cursor;
        }

        $body = $this->client->get($params);
        $mediaType = isset($filters['media_type']) && in_array($filters['media_type'], ['IMAGE', 'VIDEO'], true)
            ? strtolower((string) $filters['media_type'])
            : null;

        $rows = [];
        foreach ((array) ($body['data'] ?? []) as $node) {
            $mapped = $this->mapRow((array) $node, $mediaType);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        $next = $body['paging']['cursors']['after'] ?? null;

        return ['rows' => $rows, 'next' => is_string($next) && $next !== '' ? $next : null];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function mapRow(array $node, ?string $mediaType): ?array
    {
        $id = (string) ($node['id'] ?? '');
        if ($id === '') {
            return null;
        }
        // Defensive: never persist the token-bearing snapshot url even if present.
        unset($node['ad_snapshot_url']);

        $bodies = $this->stringList($node['ad_creative_bodies'] ?? null);
        $titles = $this->stringList($node['ad_creative_link_titles'] ?? null);

        return [
            'ad_archive_id'      => $id,
            'page_id'            => (string) ($node['page_id'] ?? ''),
            'page_name'          => isset($node['page_name']) ? (string) $node['page_name'] : null,
            'permalink'          => 'https://www.facebook.com/ads/library/?id=' . $id,
            'ad_created_at'      => $node['ad_creation_time'] ?? null,
            'delivery_start'     => isset($node['ad_delivery_start_time']) ? substr((string) $node['ad_delivery_start_time'], 0, 10) : null,
            'delivery_stop'      => isset($node['ad_delivery_stop_time']) ? substr((string) $node['ad_delivery_stop_time'], 0, 10) : null,
            'creative_bodies'    => $bodies,
            'link_titles'        => $titles,
            'link_captions'      => $this->stringList($node['ad_creative_link_captions'] ?? null),
            'link_descriptions'  => $this->stringList($node['ad_creative_link_descriptions'] ?? null),
            'languages'          => $this->stringList($node['languages'] ?? null),
            'platforms'          => $this->stringList($node['publisher_platforms'] ?? null),
            'media_type'         => $mediaType,
            // Missing ≠ 0: absent reach stays null.
            'eu_total_reach'     => isset($node['eu_total_reach']) ? (int) $node['eu_total_reach'] : null,
            'reach_breakdown'    => $node['age_country_gender_reach_breakdown'] ?? null,
            'target_ages'        => $node['target_ages'] ?? null,
            'target_gender'      => isset($node['target_gender']) ? (string) $node['target_gender'] : null,
            'target_locations'   => $node['target_locations'] ?? null,
            'beneficiary_payers' => $node['beneficiary_payers'] ?? null,
            'concept_hash'       => self::conceptHash((string) ($node['page_id'] ?? ''), $bodies, $titles, $id),
            'raw'                => $node,
        ];
    }

    /**
     * sha1(page_id | normalized concept text). Fallback chain so textless image
     * ads never collapse into ONE false mega-concept: first creative body →
     * first link title → the ad id itself (always unique).
     *
     * @param list<string> $bodies
     * @param list<string> $titles
     */
    public static function conceptHash(string $pageId, array $bodies, array $titles, string $adId): string
    {
        $seed = trim((string) ($bodies[0] ?? ''));
        if ($seed === '') {
            $seed = trim((string) ($titles[0] ?? ''));
        }
        if ($seed === '') {
            $seed = $adId; // unique → this ad is its own concept
        } else {
            $seed = mb_strtolower(mb_substr($seed, 0, 120));
        }

        return sha1($pageId . '|' . $seed);
    }

    /**
     * Normalise a Graph string-array field (some come back as scalars) to a clean
     * list of non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $v): array
    {
        if (is_string($v)) {
            $v = [$v];
        }
        if (! is_array($v)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($x) => is_string($x) ? trim($x) : '', $v), static fn ($s) => $s !== ''));
    }
}
