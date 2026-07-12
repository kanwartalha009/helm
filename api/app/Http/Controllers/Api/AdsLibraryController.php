<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdCreativeDaily;
use App\Models\AdLibraryAd;
use App\Models\AdLibraryPage;
use App\Models\Brand;
use App\Platforms\MetaAdLibrary\PageResolver;
use App\Reports\Support\AdAudit;
use App\Services\AdsLibrary\AdLibrarySync;
use App\Services\AdsLibrary\MarketAlerts;
use App\Services\AdsLibrary\SignalScorer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ads Library — internal winners (Phase 1, docs/feature-specs/ads-library.md).
 *
 * "What is actually working across OUR brands", by REAL window ROAS from
 * ad_creative_daily — the join no competitor can sell (verified first-party
 * outcomes). Every row is badged `Verified — our data`. Evidence-gated EXACTLY
 * like AdAudit (no verdict below $50 USD window spend; 'early' below $150 —
 * AdAudit::MIN_SPEND / SOLID_SPEND, the single source of truth). Ranking is USD
 * (cross-currency comparable); each row also displays in its brand's native
 * currency. Capped at 100 (it's the product name).
 *
 * RBAC: cross-brand data. master_admin / manager see every brand; everyone else
 * is scoped to their accessible brands (mirrors the Brand 'access' global scope)
 * so a brand name never leaks to an unassigned member.
 */
class AdsLibraryController extends Controller
{
    private const CAP = 100;

    public function winners(Request $request): JsonResponse
    {
        $data = $request->validate([
            'window'     => ['nullable', 'in:last30,last90'],
            'niche'      => ['nullable', 'string', 'max:48'], // '__unassigned' → brands with no niche
            'platform'   => ['nullable', 'in:meta,google,tiktok'],
            'media_type' => ['nullable', 'in:image,video'],
            'brand'      => ['nullable', 'string', 'max:120'], // brand slug
            'sort'       => ['nullable', 'in:roas,spend,ctr'],
            'search'     => ['nullable', 'string', 'max:100'],
        ]);

        $user    = $request->user();
        $isAdmin = in_array($user->role, ['master_admin', 'manager'], true);

        $windowDays = ($data['window'] ?? 'last30') === 'last90' ? 90 : 30;
        $end        = CarbonImmutable::now()->subDay()->startOfDay(); // yesterday = last complete day
        $start      = $end->subDays($windowDays - 1);

        $min = AdAudit::MIN_SPEND;   // $50 evidence floor
        $solid = AdAudit::SOLID_SPEND; // $150 → below is 'early'

        $rows = AdCreativeDaily::query()
            ->join('brands', 'brands.id', '=', 'ad_creative_daily.brand_id')
            ->whereBetween('ad_creative_daily.date', [$start->toDateString(), $end->toDateString()])
            ->when(! $isAdmin, fn ($q) => $q->whereIn('ad_creative_daily.brand_id', $user->accessibleBrandIds()))
            ->when($data['platform'] ?? null, fn ($q, $v) => $q->where('ad_creative_daily.platform', $v))
            ->when($data['media_type'] ?? null, fn ($q, $v) => $q->where('ad_creative_daily.media_type', $v))
            ->when($data['brand'] ?? null, fn ($q, $v) => $q->where('brands.slug', $v))
            ->when(
                ($data['niche'] ?? null) !== null,
                fn ($q) => ($data['niche'] === '__unassigned')
                    ? $q->whereNull('brands.niche')
                    : $q->where('brands.niche', $data['niche'])
            )
            ->when(
                ($data['search'] ?? '') !== '',
                function ($q) use ($data) {
                    $s = '%' . $data['search'] . '%';
                    $q->where(fn ($w) => $w
                        ->where('ad_creative_daily.ad_name', 'like', $s)
                        ->orWhere('brands.name', 'like', $s)
                        ->orWhere('ad_creative_daily.body_text', 'like', $s));
                }
            )
            ->groupBy(
                'ad_creative_daily.brand_id', 'ad_creative_daily.platform', 'ad_creative_daily.ad_id',
                'brands.name', 'brands.slug', 'brands.niche', 'brands.base_currency'
            )
            ->selectRaw(
                'ad_creative_daily.brand_id AS brand_id,'
                . 'ad_creative_daily.platform AS platform,'
                . 'ad_creative_daily.ad_id AS ad_id,'
                . 'brands.name AS brand_name, brands.slug AS brand_slug, brands.niche AS niche, brands.base_currency AS currency,'
                . 'MAX(ad_creative_daily.ad_name) AS ad_name,'
                . 'MAX(ad_creative_daily.body_text) AS body_text,'
                . 'MAX(ad_creative_daily.thumbnail_url) AS thumbnail_url,'
                . 'MAX(ad_creative_daily.media_type) AS media_type,'
                . 'COALESCE(SUM(ad_creative_daily.spend), 0) AS spend,'
                . 'COALESCE(SUM(ad_creative_daily.spend * COALESCE(ad_creative_daily.fx_rate_to_usd, 1)), 0) AS spend_usd,'
                . 'COALESCE(SUM(ad_creative_daily.conversion_value), 0) AS revenue,'
                . 'COALESCE(SUM(ad_creative_daily.conversion_value * COALESCE(ad_creative_daily.fx_rate_to_usd, 1)), 0) AS revenue_usd,'
                . 'COALESCE(SUM(ad_creative_daily.impressions), 0) AS impressions,'
                . 'COALESCE(SUM(ad_creative_daily.clicks), 0) AS clicks,'
                . 'COALESCE(SUM(ad_creative_daily.conversions), 0) AS purchases,'
                . 'COALESCE(SUM(ad_creative_daily.video_3s), 0) AS video_3s,'
                . 'COALESCE(SUM(ad_creative_daily.thruplays), 0) AS thruplays,'
                . 'MAX(ad_creative_daily.pulled_at) AS pulled_at'
            )
            ->get();

        $asOf = null;
        $mapped = [];
        foreach ($rows as $r) {
            $spendUsd = (float) $r->spend_usd;
            if ($spendUsd < $min) {
                continue; // evidence floor — never rank a creative we can't judge
            }
            $spend   = round((float) $r->spend, 2);
            $revenue = round((float) $r->revenue, 2);
            $impr    = (int) $r->impressions;
            $clk     = (int) $r->clicks;
            $purch   = (int) $r->purchases;
            $isVideo = (string) $r->media_type === 'video';
            // ROAS is a pure ratio (same fx on both sides) → currency-invariant;
            // compute in USD for a stable cross-brand sort.
            $roas    = $spendUsd > 0 ? round((float) $r->revenue_usd / $spendUsd, 2) : null;
            $pulled  = $r->pulled_at ? CarbonImmutable::parse((string) $r->pulled_at)->toIso8601String() : null;
            if ($pulled !== null && ($asOf === null || $pulled > $asOf)) {
                $asOf = $pulled;
            }

            $mapped[] = [
                'adId'        => (string) $r->ad_id,
                'platform'    => (string) $r->platform,
                'brand'       => ['name' => (string) $r->brand_name, 'slug' => (string) $r->brand_slug],
                'niche'       => $r->niche,
                'name'        => (string) ($r->ad_name ?: $r->ad_id),
                'bodyText'    => $r->body_text !== null && $r->body_text !== '' ? (string) $r->body_text : null,
                'thumbnailUrl' => $r->thumbnail_url ? (string) $r->thumbnail_url : null,
                'mediaType'   => $isVideo ? 'video' : 'image',
                'currency'    => (string) ($r->currency ?: 'USD'),
                'spend'       => $spend,
                'spendUsd'    => round($spendUsd, 2),
                'roas'        => $roas,
                'cpa'         => $purch > 0 ? round($spend / $purch, 2) : null,
                'ctr'         => $impr > 0 ? round($clk / $impr * 100, 2) : null,
                'purchases'   => $purch,
                // Video engagement (null on image): TS = 3s views / impressions,
                // HR = ThruPlays / impressions — same definitions as the ads hub.
                'thumbstop'   => ($isVideo && $impr > 0) ? round((int) $r->video_3s / $impr * 100, 2) : null,
                'hold'        => ($isVideo && $impr > 0) ? round((int) $r->thruplays / $impr * 100, 2) : null,
                'confidence'  => $spendUsd < $solid ? 'early' : 'solid',
            ];
        }

        // Sort (roas default), then cap 100. Nulls sort last on ratio metrics.
        $sort = $data['sort'] ?? 'roas';
        usort($mapped, static fn (array $a, array $b): int => match ($sort) {
            'spend' => $b['spendUsd'] <=> $a['spendUsd'],
            'ctr'   => ($b['ctr'] ?? -1) <=> ($a['ctr'] ?? -1),
            default => ($b['roas'] ?? -1) <=> ($a['roas'] ?? -1),
        });
        $capped = array_slice($mapped, 0, self::CAP);

        // Niche options for the filter (accessible brands only — Brand global scope).
        $niches = Brand::query()->whereNotNull('niche')->distinct()->orderBy('niche')->pluck('niche')->all();

        return response()->json([
            'window'      => $windowDays === 90 ? 'last90' : 'last30',
            'periodStart' => $start->toDateString(),
            'periodEnd'   => $end->toDateString(),
            'asOf'        => $asOf,
            'total'       => count($mapped),
            'cap'         => self::CAP,
            'rows'        => $capped,
            'niches'      => array_values($niches),
        ]);
    }

    /**
     * Market library (Phase 3) — reads ONLY the stored corpus (no live API on page
     * views), collapsed to ONE card per concept_hash (highest-scoring variant
     * represents the concept, with a variant count), sorted by the disclosed Signal
     * Score. Every metric is Proxy — public signals (EU-disclosed reach + longevity
     * + variant count); commercial ads expose no spend, so this is NEVER called
     * performance. Viewing is open to any authenticated user (shared public data);
     * tracking + live search are admin/manager only (separate routes).
     */
    public function market(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'           => ['nullable', 'string', 'max:100'],
            'niche'       => ['nullable', 'string', 'max:48'],
            'media_type'  => ['nullable', 'in:image,video'],
            'active'      => ['nullable', 'in:1,0,all'],
            'page_id'     => ['nullable', 'string', 'max:40'],
            'sort'        => ['nullable', 'in:signal,rising,newest,longevity,reach'],
            'limit'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit   = (int) ($data['limit'] ?? 100);
        $active  = $data['active'] ?? '1';
        $sort    = $data['sort'] ?? 'signal';

        $candidates = AdLibraryAd::query()
            ->when($data['niche'] ?? null, fn ($q, $v) => $q->where('niche', $v))
            ->when($data['media_type'] ?? null, fn ($q, $v) => $q->where('media_type', $v))
            ->when($data['page_id'] ?? null, fn ($q, $v) => $q->where('page_id', $v))
            ->when($active !== 'all', fn ($q) => $q->where('is_active', $active === '1'))
            ->when($data['q'] ?? null, function ($q, $v) {
                $s = '%' . $v . '%';
                $q->where(fn ($w) => $w->where('page_name', 'like', $s)->orWhere('creative_bodies', 'like', $s));
            })
            // Rising = reach velocity (EU reach ÷ days live) — a disclosed Proxy sort
            // that surfaces YOUNG ads pulling unusually fast reach, without pretending
            // they're proven (raw longevity punishes them; §3 ranking design). The
            // *1.0 forces float division (sqlite does integer division otherwise) and
            // NULLIF guards day-0 ads; null reach or 0 days → NULL → sorts last (DESC).
            ->when($sort === 'rising', fn ($q) => $q->orderByRaw('(eu_total_reach * 1.0 / NULLIF(longevity_days, 0)) DESC'))
            ->when($sort !== 'rising', fn ($q) => $q->orderByDesc(match ($sort) {
                'newest'    => 'delivery_start',
                'longevity' => 'longevity_days',
                'reach'     => 'eu_total_reach',
                default     => 'signal_score',
            }))
            ->limit(1000) // bound before concept collapse (v1); refine if a niche exceeds it
            ->get();

        // Collapse to concepts — first (highest-sorted) row per concept_hash is the
        // representative; count the variants behind it.
        $concepts = [];
        foreach ($candidates as $ad) {
            $h = (string) $ad->concept_hash;
            if (! isset($concepts[$h])) {
                $concepts[$h] = ['rep' => $ad, 'variants' => 0];
            }
            $concepts[$h]['variants']++;
        }

        $rows = [];
        foreach (array_slice(array_values($concepts), 0, $limit) as $c) {
            /** @var AdLibraryAd $ad */
            $ad = $c['rep'];
            $rows[] = [
                'adArchiveId'  => (string) $ad->ad_archive_id,
                'pageId'       => (string) $ad->page_id,
                'pageName'     => $ad->page_name,
                'niche'        => $ad->niche,
                'permalink'    => $ad->permalink,
                'mediaType'    => $ad->media_type,
                'deliveryStart' => $ad->delivery_start?->toDateString(),
                'deliveryStop' => $ad->delivery_stop?->toDateString(),
                'isActive'     => (bool) $ad->is_active,
                'longevityDays' => $ad->longevity_days,
                'euReach'      => $ad->eu_total_reach,
                'signalScore'  => $ad->signal_score,
                'variants'     => $c['variants'],
                'bodies'       => (array) ($ad->creative_bodies ?? []),
                'linkTitles'   => (array) ($ad->link_titles ?? []),
                'languages'    => (array) ($ad->languages ?? []),
                'platforms'    => (array) ($ad->platforms ?? []),
                'targetAges'   => $ad->target_ages,
                'targetGender' => $ad->target_gender,
                'targetLocations' => $ad->target_locations,
                'reachBreakdown'  => $ad->reach_breakdown,
                'beneficiaryPayers' => $ad->beneficiary_payers,
            ];
        }

        return response()->json([
            'rows'         => $rows,
            'total'        => count($concepts),
            'cap'          => $limit,
            'sort'         => $data['sort'] ?? 'signal',
            // Disclosed verbatim in the UI tooltip — the score is a sort key, never
            // performance. Coverage honesty: EU delivery only.
            'scoreWeights' => (array) config('adslibrary.score', []),
            'coverageNote' => 'EU delivery only — US-only campaigns are not visible. Metrics are Proxy — public signals (reach, longevity, variants), never spend or ROAS.',
        ]);
    }

    /** GET tracked competitor pages + per-page ad counts (Phase 3). */
    public function pages(): JsonResponse
    {
        $pages = AdLibraryPage::query()->orderByDesc('id')->get()->map(function (AdLibraryPage $p): array {
            $base = AdLibraryAd::query()->where('page_id', $p->page_id);
            return [
                'id'            => $p->id,
                'pageId'        => $p->page_id,
                'pageName'      => $p->page_name,
                'niche'         => $p->niche,
                'countryDefault' => $p->country_default,
                'status'        => $p->status,
                'lastRefreshedAt' => $p->last_refreshed_at?->toIso8601String(),
                'activeAds'     => (clone $base)->where('is_active', true)->count(),
                'newThisWeek'   => (clone $base)->where('first_seen_at', '>=', now()->subDays(7))->count(),
            ];
        });

        return response()->json(['pages' => $pages]);
    }

    /** POST resolve a pasted URL / id / name → candidate page ids (admin/manager route). */
    public function resolvePage(Request $request, PageResolver $resolver): JsonResponse
    {
        $data = $request->validate(['input' => ['required', 'string', 'max:300']]);

        return response()->json($resolver->resolve($data['input']));
    }

    /** POST track a page (admin/manager route). Idempotent on (workspace_id, page_id). */
    public function trackPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page_id'         => ['required', 'string', 'max:40'],
            'page_name'       => ['nullable', 'string', 'max:255'],
            'niche'           => ['nullable', 'string', 'max:48'],
            'country_default' => ['nullable', 'string', 'max:8'],
        ]);

        $page = AdLibraryPage::updateOrCreate(
            ['workspace_id' => null, 'page_id' => $data['page_id']],
            [
                'page_name'       => $data['page_name'] ?? null,
                'niche'           => $data['niche'] ?? null,
                'country_default' => $data['country_default'] ?? null,
                'status'          => 'active',
                'added_by_user_id' => $request->user()->id,
            ],
        );

        return response()->json(['id' => $page->id, 'pageId' => $page->page_id], 201);
    }

    /** DELETE stop tracking (keep history) — status → paused (admin/manager route). */
    public function untrackPage(AdLibraryPage $page): JsonResponse
    {
        $page->update(['status' => 'paused']);

        return response()->json(['ok' => true]);
    }

    /**
     * POST "Search Meta live" (admin/manager route) — one byTerms call that upserts
     * into the corpus + rescores — so ad-hoc searches enrich the shared library
     * permanently. Returns how many ads landed; the client then re-queries market().
     */
    public function liveSearch(Request $request, AdLibrarySync $sync, SignalScorer $scorer): JsonResponse
    {
        $data = $request->validate([
            'q'           => ['required', 'string', 'max:100'],
            'search_type' => ['nullable', 'in:KEYWORD_UNORDERED,KEYWORD_EXACT_PHRASE'],
            'niche'       => ['nullable', 'string', 'max:48'],
            'media_type'  => ['nullable', 'in:IMAGE,VIDEO'],
        ]);
        $countries = (array) (\App\Models\WorkspaceSetting::getValue('adlib_countries', null) ?: config('adslibrary.default_countries', ['ES']));
        $filters = [];
        if (! empty($data['media_type'])) {
            $filters['media_type'] = $data['media_type'];
        }

        $upserted = $sync->ingestTerms(
            $data['q'],
            $data['search_type'] ?? 'KEYWORD_UNORDERED',
            $countries,
            $filters,
            $data['niche'] ?? null,
            2, // ad-hoc: cap at 2 cursor pages
        );
        $scorer->materialize();

        return response()->json(['upserted' => $upserted]);
    }

    /** GET "This week in your market" — deterministic competitor movement alerts (Phase 5). */
    public function alerts(Request $request, MarketAlerts $alerts): JsonResponse
    {
        $data = $request->validate(['niche' => ['nullable', 'string', 'max:48']]);

        return response()->json(['alerts' => $alerts->forPages($data['niche'] ?? null)]);
    }
}
