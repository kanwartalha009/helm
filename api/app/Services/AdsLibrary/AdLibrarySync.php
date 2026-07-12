<?php

declare(strict_types=1);

namespace App\Services\AdsLibrary;

use App\Models\AdLibraryAd;
use App\Models\AdLibraryPage;
use App\Models\AdLibrarySearch;
use App\Platforms\MetaAdLibrary\ArchiveFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fetch → upsert → label → active/inactive for the market corpus (Ads Library
 * Phase 2). Delegates HTTP to ArchiveFetcher (guardrail). Idempotent: keyed on
 * ad_archive_id, a rerun updates rather than duplicates; first_seen_at is set on
 * insert and never overwritten. Media type is derived from the ALL/IMAGE/VIDEO
 * sweep (the node has none) — the null-ALL sweep never clobbers a prior label.
 */
class AdLibrarySync
{
    /** JSON columns (upsert bypasses Eloquent casts → encode here). */
    private const JSON_COLS = [
        'countries', 'creative_bodies', 'link_titles', 'link_captions', 'link_descriptions',
        'languages', 'platforms', 'reach_breakdown', 'target_ages', 'target_locations',
        'beneficiary_payers', 'raw',
    ];

    public function __construct(private readonly ArchiveFetcher $fetcher) {}

    /**
     * Sweep one tracked page (ALL, then IMAGE, then VIDEO to label media), paginate
     * up to $maxPages per sweep, upsert, then mark previously-active ads that this
     * run did NOT see as inactive.
     *
     * @param array<int, string> $countries
     * @return array{upserted: int, seen: int}
     */
    public function syncPage(AdLibraryPage $page, array $countries, int $maxPages): array
    {
        $seen = [];
        $upserted = 0;

        foreach ([null, 'IMAGE', 'VIDEO'] as $media) {
            $cursor = null;
            $pages  = 0;
            do {
                $filters = ['ad_active_status' => 'ACTIVE'];
                if ($media !== null) {
                    $filters['media_type'] = $media;
                }
                $res = $this->fetcher->byPages([(string) $page->page_id], $countries, $filters, $cursor);
                $upserted += $this->upsertAds($res['rows'], $page->niche, $seen, labelMedia: $media !== null);
                $cursor = $res['next'];
                $pages++;
                if ($pages >= $maxPages && $cursor !== null) {
                    Log::info('adlib.page_sweep_truncated', ['page_id' => $page->page_id, 'media' => $media ?? 'ALL', 'pages' => $pages]);
                    break;
                }
            } while ($cursor !== null);
        }

        $this->markInactive((string) $page->page_id, array_keys($seen));
        $page->forceFill(['last_refreshed_at' => now()])->save();

        return ['upserted' => $upserted, 'seen' => count($seen)];
    }

    /**
     * Run one saved search (byTerms). Keyword ads legitimately drop in/out of a
     * match, so searches upsert only — no active/inactive marking.
     */
    public function syncSearch(AdLibrarySearch $search, int $maxPages): int
    {
        $countries = (array) ($search->countries ?: config('adslibrary.default_countries', ['ES']));
        $n = $this->ingestTerms((string) $search->terms, (string) $search->search_type, $countries, (array) ($search->filters ?? []), $search->niche, $maxPages);
        $search->forceFill(['last_run_at' => now()])->save();

        return $n;
    }

    /**
     * Ad-hoc keyword ingest (also powers the "Search Meta live" action) — byTerms +
     * upsert, no active/inactive marking (keyword matches drift legitimately).
     *
     * @param array<int, string>   $countries
     * @param array<string, mixed> $filters
     */
    public function ingestTerms(string $terms, string $searchType, array $countries, array $filters, ?string $niche, int $maxPages): int
    {
        $filters['ad_active_status'] ??= 'ALL';
        $seen = [];
        $upserted = 0;
        $cursor = null;
        $pages  = 0;
        do {
            $res = $this->fetcher->byTerms($terms, $searchType, $countries, $filters, $cursor);
            $upserted += $this->upsertAds($res['rows'], $niche, $seen, labelMedia: ! empty($filters['media_type']));
            $cursor = $res['next'];
            $pages++;
        } while ($cursor !== null && $pages < $maxPages);

        return $upserted;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, bool>        $seen  by-ref, ad_archive_id => true
     */
    private function upsertAds(array $rows, ?string $niche, array &$seen, bool $labelMedia): int
    {
        if ($rows === []) {
            return 0;
        }
        $now = now();
        $records = [];
        foreach ($rows as $r) {
            $id = (string) $r['ad_archive_id'];
            if ($id === '') {
                continue;
            }
            $seen[$id] = true;

            $rec = [
                'ad_archive_id'  => $id,
                'page_id'        => (string) $r['page_id'],
                'page_name'      => $r['page_name'],
                'niche'          => $niche,
                'permalink'      => $r['permalink'],
                'ad_created_at'  => $r['ad_created_at'],
                'delivery_start' => $r['delivery_start'],
                'delivery_stop'  => $r['delivery_stop'],
                'media_type'     => $r['media_type'],
                'eu_total_reach' => $r['eu_total_reach'],
                'target_gender'  => $r['target_gender'],
                'concept_hash'   => $r['concept_hash'],
                'is_active'      => true,
                'first_seen_at'  => $now,
                'last_seen_at'   => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
            foreach (self::JSON_COLS as $c) {
                if (array_key_exists($c, $r)) {
                    $rec[$c] = $r[$c] !== null ? json_encode($r[$c]) : null;
                }
            }
            $records[] = $rec;
        }
        if ($records === []) {
            return 0;
        }

        // Update columns exclude first_seen_at (preserve the original sighting) and
        // media_type on the ALL sweep (so a null never clobbers a real label).
        $update = [
            'page_name', 'niche', 'permalink', 'ad_created_at', 'delivery_start', 'delivery_stop',
            'eu_total_reach', 'target_gender', 'concept_hash', 'is_active', 'last_seen_at', 'updated_at',
            ...self::JSON_COLS,
        ];
        if ($labelMedia) {
            $update[] = 'media_type';
        }

        foreach (array_chunk($records, 200) as $chunk) {
            AdLibraryAd::upsert($chunk, ['ad_archive_id'], $update);
        }

        return count($records);
    }

    /**
     * Ads for this page that were active but absent from this run's ACTIVE sweep →
     * is_active=false, delivery_stop = COALESCE(existing, today). $today is a
     * controlled date string (safe to interpolate).
     *
     * @param array<int, string> $seenIds
     */
    private function markInactive(string $pageId, array $seenIds): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $q = AdLibraryAd::query()->where('page_id', $pageId)->where('is_active', true);
        if ($seenIds !== []) {
            $q->whereNotIn('ad_archive_id', $seenIds);
        }
        $q->update([
            'is_active'     => false,
            'delivery_stop' => DB::raw("COALESCE(delivery_stop, '" . $today . "')"),
            'updated_at'    => now(),
        ]);
    }
}
