<?php

declare(strict_types=1);

namespace App\Platforms\MetaAdLibrary;

use Throwable;

/**
 * Resolves a pasted Ad Library / Facebook URL, a numeric page id, or a page name
 * into candidate page ids for tracking (Ads Library Phase 2). URLs with a
 * `view_all_page_id` (or a numeric id) resolve directly; a name falls back to
 * `GET /pages/search` (documented ~500 calls/user/day). Name collisions are
 * common, so this NEVER auto-tracks a name match — it returns candidates and the
 * operator confirms.
 */
class PageResolver
{
    public function __construct(private readonly AdLibraryClient $client) {}

    /**
     * @return array{pageId: ?string, candidates: list<array{pageId: string, pageName: ?string}>, source: string}
     */
    public function resolve(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['pageId' => null, 'candidates' => [], 'source' => 'empty'];
        }

        // Ad Library URL carrying the page id.
        if (preg_match('~[?&]view_all_page_id=(\d{5,})~', $input, $m)) {
            return $this->single($m[1], 'url');
        }
        // Bare numeric page id pasted directly.
        if (preg_match('~^\d{5,}$~', $input)) {
            return $this->single($input, 'id');
        }
        // facebook.com/profile.php?id=… style URL.
        if (preg_match('~facebook\.com/.*[?&]id=(\d{5,})~', $input, $m)) {
            return $this->single($m[1], 'url');
        }

        return $this->searchByName($input);
    }

    /** @return array{pageId: string, candidates: list<array{pageId: string, pageName: ?string}>, source: string} */
    private function single(string $pageId, string $source): array
    {
        return ['pageId' => $pageId, 'candidates' => [['pageId' => $pageId, 'pageName' => null]], 'source' => $source];
    }

    /** @return array{pageId: ?string, candidates: list<array{pageId: string, pageName: ?string}>, source: string} */
    private function searchByName(string $q): array
    {
        try {
            $body = $this->client->get(['q' => $q, 'fields' => 'id,name', 'limit' => 10], 'pages/search');
        } catch (Throwable) {
            // /pages/search may be unavailable with an ads_read-only token — the
            // operator can paste the Ad Library URL instead (VERIFY on a live app).
            return ['pageId' => null, 'candidates' => [], 'source' => 'search_unavailable'];
        }

        $candidates = [];
        foreach ((array) ($body['data'] ?? []) as $p) {
            $id = (string) ($p['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $candidates[] = ['pageId' => $id, 'pageName' => isset($p['name']) ? (string) $p['name'] : null];
        }

        return [
            'pageId'     => count($candidates) === 1 ? $candidates[0]['pageId'] : null,
            'candidates' => $candidates,
            'source'     => 'search',
        ];
    }
}
