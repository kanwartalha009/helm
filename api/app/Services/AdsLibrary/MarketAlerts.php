<?php

declare(strict_types=1);

namespace App\Services\AdsLibrary;

use App\Models\AdLibraryAd;
use App\Models\AdLibraryPage;
use Carbon\CarbonImmutable;

/**
 * Competitor movement alerts (Ads Library Phase 5.1) — deterministic, from the
 * stored corpus. No LLM, no scraped engagement. Three signals per tracked page:
 *  - new ads this week (first_seen in the last 7 days),
 *  - concept-variant spike (this week's new ads ≥ the prior active count — a
 *    doubling proxy for the "20→100" scaling move; corpus stores current state,
 *    not daily snapshots, so this approximates the ≥2× 30-day-median rule),
 *  - new-format adoption (first active video from a page that only ran statics).
 *
 * Tenant note (D-022): reads only the tracked pages (per-workspace) — no
 * cross-tenant blending.
 */
class MarketAlerts
{
    /** @return list<array{pageId: string, pageName: ?string, niche: ?string, type: string, severity: string, message: string}> */
    public function forPages(?string $niche = null): array
    {
        $weekAgo = CarbonImmutable::now()->subDays(7);
        $pages = AdLibraryPage::query()
            ->where('status', 'active')
            ->when($niche, fn ($q, $v) => $q->where('niche', $v))
            ->get();

        $alerts = [];
        foreach ($pages as $page) {
            $ads = AdLibraryAd::query()->where('page_id', $page->page_id)->get(['is_active', 'media_type', 'first_seen_at']);
            if ($ads->isEmpty()) {
                continue;
            }
            $active      = $ads->where('is_active', true);
            $activeCount = $active->count();
            $newThisWeek = $active->filter(fn ($a) => $a->first_seen_at && $a->first_seen_at->gte($weekAgo))->count();
            $prior       = $activeCount - $newThisWeek;

            $meta = ['pageId' => (string) $page->page_id, 'pageName' => $page->page_name, 'niche' => $page->niche];

            if ($newThisWeek > 0) {
                $alerts[] = $meta + [
                    'type' => 'new_ads', 'severity' => 'info',
                    'message' => ($page->page_name ?? 'A tracked page') . " launched {$newThisWeek} new ad" . ($newThisWeek === 1 ? '' : 's') . ' this week.',
                ];
            }

            if ($activeCount >= 4 && $newThisWeek >= max(1, $prior)) {
                $alerts[] = $meta + [
                    'type' => 'variant_spike', 'severity' => 'warn',
                    'message' => ($page->page_name ?? 'A tracked page') . " roughly doubled its live ads ({$prior} → {$activeCount}) — a scaling push.",
                ];
            }

            $newVideo   = $active->contains(fn ($a) => $a->media_type === 'video' && $a->first_seen_at && $a->first_seen_at->gte($weekAgo));
            $olderVideo = $ads->contains(fn ($a) => $a->media_type === 'video' && $a->first_seen_at && $a->first_seen_at->lt($weekAgo));
            if ($newVideo && ! $olderVideo) {
                $alerts[] = $meta + [
                    'type' => 'new_format', 'severity' => 'info',
                    'message' => ($page->page_name ?? 'A tracked page') . ' ran its first video ad — testing a new format.',
                ];
            }
        }

        return $alerts;
    }
}
