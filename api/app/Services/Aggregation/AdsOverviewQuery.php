<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\AdCampaignDailyMetric;
use App\Models\AdCreativeDaily;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\MetaBreakdownDaily;
use Carbon\CarbonImmutable;

/**
 * Assembles the per-brand Ads Overview (the "Ads" hub) for one platform — Meta
 * today, built platform-agnostically so Google/TikTok drop in later. Reads only
 * data we already sync: daily_metrics (brand×platform×day) for the KPI summary
 * and the trend series, meta_breakdown_daily for the country + device splits,
 * and ad_campaign_daily_metrics for the campaign table.
 *
 * Metrics are PLATFORM-ATTRIBUTED — Meta's 7d_click purchases (conversions) and
 * value (conversion_value) — not blended Shopify revenue. An ads view ranks
 * campaigns / countries / devices, and only the ad platform can attribute a
 * purchase to one of those. The main dashboard keeps blended ROAS; this is the
 * ad-reported view.
 *
 * Currency + timezone follow DashboardQuery/AudienceQuery: money is summed in
 * the brand's native currency (or ×fx to USD when ?currency=USD), and the window
 * is computed in the BRAND's timezone, ending yesterday (today is partial — the
 * live sync owns it).
 */
final class AdsOverviewQuery
{
    /** Only Meta is wired today; the response shape is platform-agnostic. */
    private const PLATFORM = 'meta';

    /** Country/device tables are long-tailed — cap the rows we ship (the UI shows
     *  a top-N with a "View all" that reveals the rest up to this cap). */
    private const MAX_COUNTRY_ROWS = 30;

    /**
     * @param array<string, mixed> $params  period|from|to|currency
     * @return array<string, mixed>
     */
    public function run(Brand $brand, array $params): array
    {
        $tz       = $brand->timezone ?: 'UTC';
        $usd      = strtoupper((string) ($params['currency'] ?? '')) === 'USD';
        $platform = $this->resolvePlatform($params);
        $isMeta   = $platform === 'meta';

        [$start, $end]           = $this->window($params, $tz);
        [$priorStart, $priorEnd] = $this->priorWindow($start, $end);

        // Native by default; ×fx to USD when the currency toggle is on. Applied
        // to every money column so cross-currency views stay comparable.
        $money = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;

        $summary = $this->summary((int) $brand->id, $platform, $start, $end, $priorStart, $priorEnd, $money);

        // Breakdowns (audience view + region/device) come from meta_breakdown_daily,
        // now Meta + TikTok. Placement + ASC-audience axes stay Meta-only (TikTok
        // has no equivalent). age_gender fetched once → age×gender / gender / age.
        $breakdownable = in_array($platform, ['meta', 'tiktok'], true);
        // Google has the spend-based breakdowns (device + country/region) but NOT
        // demographics / placement / ASC-audience, so those wider panels gate on
        // this set while age-gender / placement / audience stay Meta+TikTok.
        $geoDeviceable = $breakdownable || $platform === 'google';
        $ag = $breakdownable ? $this->ageGenderBreakdowns((int) $brand->id, $start, $end, $money, $platform) : null;

        return [
            'brand' => [
                'id'           => (int) $brand->id,
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'initials'     => $this->initials((string) $brand->name),
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $tz,
                // Ad platforms with an ACTIVE connection on this brand — the UI
                // only offers a platform toggle for what's actually connected, and
                // hides Meta-only tabs on non-Meta platforms.
                'platforms'    => $brand->connections()
                    ->where('status', 'active')
                    ->whereIn('platform', ['meta', 'google', 'tiktok'])
                    ->pluck('platform')
                    ->unique()
                    ->values()
                    ->all(),
            ],
            'platform'   => $platform,
            'period'     => strtolower((string) ($params['period'] ?? 'last30')),
            'from'       => $start,
            'to'         => $end,
            'currency'   => $usd ? 'usd' : 'native',
            'isComplete' => $summary['isComplete'],
            'summary'    => $summary['metrics'],
            'trend'      => $this->trend((int) $brand->id, $platform, $start, $end, $money),
            'funnel'     => $summary['funnel'],
            // Breakdowns (map / donut / audience) live in meta_breakdown_daily —
            // Meta only. For Google/TikTok they're not applicable, so the panels
            // degrade to a "Meta only" state rather than show Meta's numbers under
            // a Google view.
            'byCountry'         => $geoDeviceable ? $this->breakdown((int) $brand->id, 'country', $start, $end, $money, $platform) : $this->notApplicable(),
            'byDevice'          => $geoDeviceable ? $this->deviceSplit((int) $brand->id, $start, $end, $money, $platform) : ['hasData' => false, 'metric' => 'purchases', 'total' => 0, 'rows' => []],
            'byAgeGender'       => $ag['ageGender'] ?? $this->notApplicable(),
            'byGender'          => $ag['gender'] ?? $this->notApplicable(),
            'byAge'             => $ag['age'] ?? $this->notApplicable(),
            'byPlacement'       => $isMeta ? $this->breakdown((int) $brand->id, 'placement_platform', $start, $end, $money) : $this->notApplicable(),
            'byPlacementDetail' => $isMeta ? $this->breakdown((int) $brand->id, 'placement', $start, $end, $money) : $this->notApplicable(),
            'byDeviceDetail'    => $geoDeviceable ? $this->breakdown((int) $brand->id, 'device', $start, $end, $money, $platform) : $this->notApplicable(),
            'byAudience'        => $isMeta ? $this->breakdown((int) $brand->id, 'audience', $start, $end, $money) : $this->notApplicable(),
            'byRegion'          => $geoDeviceable ? $this->regionRollup((int) $brand->id, $start, $end, $money, $platform) : $this->notApplicable(),
            'byChannel'         => $platform === 'google' ? $this->channelBreakdown((int) $brand->id, $start, $end, $money) : $this->notApplicable(),
            'byBrandType'       => $platform === 'google' ? $this->brandSplit((int) $brand->id, $start, $end, $money) : $this->notApplicable(),
            'tiktokNative'      => $platform === 'tiktok' ? $this->tiktokNative((int) $brand->id, $start, $end) : null,
            'metaNative'        => $platform === 'meta' ? $this->metaNative((int) $brand->id, $start, $end) : null,
            'campaigns'         => $this->campaigns((int) $brand->id, $platform, $start, $end, $priorStart, $priorEnd, $money),
        ];
    }

    /**
     * KPI block from daily_metrics + prior-window deltas + the freshness gate.
     *
     * @return array{metrics: array<string, mixed>, isComplete: bool}
     */
    private function summary(int $brandId, string $platform, string $start, string $end, string $priorStart, string $priorEnd, callable $money): array
    {
        $agg = fn (string $s, string $e) => DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$s, $e])
            ->selectRaw(
                'COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks,
                 COALESCE(SUM(reach), 0)                              AS reach,
                 COALESCE(SUM(link_clicks), 0)                        AS link_clicks,
                 COALESCE(SUM(landing_page_views), 0)                 AS landing_page_views,
                 COUNT(reach)                                         AS reach_n,
                 COUNT(link_clicks)                                   AS lc_n,
                 COUNT(landing_page_views)                            AS lpv_n,
                 SUM(CASE WHEN is_complete THEN 1 ELSE 0 END)         AS complete_days"
            )
            ->first();

        $cur     = $agg($start, $end);
        $metrics = $this->derive($cur);
        $prior   = $this->derive($agg($priorStart, $priorEnd));

        $delta = [];
        foreach (['spend', 'revenue', 'purchases', 'roas', 'cpa', 'aov', 'cpm', 'cpc', 'ctr', 'impressions', 'clicks'] as $k) {
            $delta[$k] = $this->pctDelta((float) ($prior[$k] ?? 0), (float) ($metrics[$k] ?? 0));
        }
        $metrics['delta'] = $delta;

        // Freshness gate (Bosco, 2026-06-30): only "complete" when every day in the
        // window has a finalized Meta row — otherwise the sync hasn't fully run and
        // the frontend renders an amber "not synced", never a partial-window total.
        // reach/frequency — null (not 0) until the funnel fields have been synced
        // for this window (reach_n = 0 = every day predates the backfill). reach
        // summed across days is an upper bound, so frequency is an approximation.
        $reachN = (int) ($cur->reach_n ?? 0);
        $reach  = (int) ($cur->reach ?? 0);
        $metrics['reach']     = $reachN > 0 ? $reach : null;
        $metrics['frequency'] = ($reachN > 0 && $reach > 0) ? round((int) $metrics['impressions'] / $reach, 2) : null;

        $expectedDays = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
        $isComplete   = $expectedDays > 0 && (int) ($cur->complete_days ?? 0) >= $expectedDays;

        return ['metrics' => $metrics, 'isComplete' => $isComplete, 'funnel' => $this->funnel($metrics, $cur, $platform)];
    }

    /**
     * Derive the display metrics from a summed row. Ratios are null (not zero)
     * when their denominator is zero, so the frontend shows "—" instead of a
     * misleading 0.00× / €0 CPA.
     *
     * @return array<string, mixed>
     */
    private function derive(?object $r): array
    {
        $spend = (float) ($r->spend ?? 0);
        $rev   = (float) ($r->revenue ?? 0);
        $purch = (int) ($r->purchases ?? 0);
        $impr  = (int) ($r->impressions ?? 0);
        $clk   = (int) ($r->clicks ?? 0);

        return [
            'spend'       => round($spend, 2),
            'revenue'     => round($rev, 2),
            'purchases'   => $purch,
            'impressions' => $impr,
            'clicks'      => $clk,
            'roas'        => $spend > 0 ? round($rev / $spend, 2) : null,
            'cpa'         => $purch > 0 ? round($spend / $purch, 2) : null,
            'aov'         => $purch > 0 ? round($rev / $purch, 2) : null,
            'cpm'         => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
            'cpc'         => $clk > 0 ? round($spend / $clk, 2) : null,
            'ctr'         => $impr > 0 ? round($clk / $impr * 100, 2) : null,
        ];
    }

    /** % change prior→current; null when there's no baseline (avoids a fake ∞%). */
    private function pctDelta(float $prior, float $current): ?float
    {
        if ($prior <= 0.0) {
            return null;
        }

        return round(($current - $prior) / $prior * 100, 1);
    }

    /**
     * Daily series for the trends chart — one row per day in the window.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trend(int $brandId, string $platform, string $start, string $end, callable $money): array
    {
        return DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw(
                'date,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get()
            ->map(static fn ($r) => [
                'date'        => CarbonImmutable::parse((string) $r->date)->toDateString(),
                'spend'       => round((float) $r->spend, 2),
                'revenue'     => round((float) $r->revenue, 2),
                'purchases'   => (int) $r->purchases,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
            ])
            ->all();
    }

    /**
     * Purchase funnel: Impressions → Link clicks → Landing views → Purchases. A
     * middle step is `pending` (value null) only when EVERY day in the window
     * predates the funnel-field sync (its COUNT is 0) — so it reads "not synced",
     * never a fake 0. Once `ads:backfill-spend` or the daily sync fills the days,
     * real values appear.
     *
     * @param array<string, mixed> $m
     * @return array<int, array<string, mixed>>
     */
    private function funnel(array $m, object $cur, string $platform): array
    {
        $impressions = ['key' => 'impressions', 'label' => 'Impressions', 'value' => (int) $m['impressions'], 'pending' => false];
        $purchases   = ['key' => 'purchases',   'label' => 'Purchases',   'value' => (int) $m['purchases'],   'pending' => false];

        // Link clicks + Landing views are Meta-specific funnel fields. TikTok and
        // Google don't sync them, so their funnel is Impressions → Clicks →
        // Purchases (Clicks is universal) — no fake "not synced" middle steps.
        if ($platform !== 'meta') {
            return [
                $impressions,
                ['key' => 'clicks', 'label' => 'Clicks', 'value' => (int) $m['clicks'], 'pending' => false],
                $purchases,
            ];
        }

        $lcN  = (int) ($cur->lc_n ?? 0);
        $lpvN = (int) ($cur->lpv_n ?? 0);

        return [
            $impressions,
            ['key' => 'link_clicks',        'label' => 'Link clicks',   'value' => $lcN > 0 ? (int) $cur->link_clicks : null,        'pending' => $lcN === 0],
            ['key' => 'landing_page_views', 'label' => 'Landing views', 'value' => $lpvN > 0 ? (int) $cur->landing_page_views : null, 'pending' => $lpvN === 0],
            $purchases,
        ];
    }

    /**
     * A stored breakdown axis (country) rolled up over the window: top segment +
     * ranked rows. Empty (hasData=false) until `meta:backfill-breakdown` has run
     * for this axis — the frontend then shows "not synced", not €0.
     *
     * @return array{hasData: bool, top: array<string, mixed>|null, rows: array<int, array<string, mixed>>}
     */
    private function breakdown(int $brandId, string $type, string $start, string $end, callable $money, string $platform = 'meta'): array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('breakdown_type', $type)
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key', 'segment_label')
            ->selectRaw(
                'segment_key, segment_label,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get();

        return $this->packRows($rows->map(static fn ($r) => [
            'key'         => (string) $r->segment_key,
            'label'       => (string) ($r->segment_label ?: $r->segment_key),
            'spend'       => (float) $r->spend,
            'revenue'     => (float) $r->revenue,
            'purchases'   => (int) $r->purchases,
            'impressions' => (int) $r->impressions,
            'clicks'      => (int) $r->clicks,
        ])->all());
    }

    /**
     * Google "channel mix" breakdown — folds the brand's Google campaigns
     * (ad_campaign_daily_metrics, ALREADY synced) into channel segments by parsing
     * the campaign name: Performance Max / Search·Brand / Search·Generic / Shopping
     * / Display / Video / Demand Gen / Other. This is the Google-native cut a DTC
     * agency actually reviews (Google exposes no account-wide age/gender for PMax),
     * and it needs NO new sync. Name-parsing (googleChannel) is convention-based
     * with an 'Other' fallback, so an unrecognised name degrades gracefully.
     */
    private function channelBreakdown(int $brandId, string $start, string $end, callable $money): array
    {
        $seg = [];
        foreach ($this->googleCampaignRows($brandId, $start, $end, $money) as $r) {
            [$key, $label] = $this->googleChannel((string) $r->campaign_name);
            $seg[$key] ??= ['key' => $key, 'label' => $label, 'spend' => 0.0, 'revenue' => 0.0, 'purchases' => 0, 'impressions' => 0, 'clicks' => 0];
            $seg[$key]['spend']       += (float) $r->spend;
            $seg[$key]['revenue']     += (float) $r->revenue;
            $seg[$key]['purchases']   += (int) $r->purchases;
            $seg[$key]['impressions'] += (int) $r->impressions;
            $seg[$key]['clicks']      += (int) $r->clicks;
        }

        return $this->packRows(array_values($seg));
    }

    /**
     * Classify a Google campaign into a channel segment from its name. Order
     * matters: GENERIC is tested before BRAND because "BRAND-GENERIC" contains
     * both tokens. Convention-based (fits the NP_<cc>_GADS_<CHANNEL>_… scheme);
     * anything unrecognised falls to Other. Not white-label-proof — a later pass
     * can store the real advertising_channel_type on the campaign row for channel
     * certainty independent of naming.
     *
     * @return array{0: string, 1: string} [key, label]
     */
    private function googleChannel(string $name): array
    {
        $n = strtoupper($name);

        if (str_contains($n, 'PMAX') || str_contains($n, 'PERFORMANCE MAX') || str_contains($n, 'PERFORMANCE_MAX')) {
            return ['pmax', 'Performance Max'];
        }
        if (str_contains($n, 'SHOPPING')) {
            return ['shopping', 'Shopping'];
        }
        if (str_contains($n, 'SEARCH')) {
            if (str_contains($n, 'GENERIC') || str_contains($n, 'NONBRAND') || str_contains($n, 'NON-BRAND')) {
                return ['search_generic', 'Search · Generic'];
            }
            if (str_contains($n, 'BRAND') || str_contains($n, 'PURE')) {
                return ['search_brand', 'Search · Brand'];
            }

            return ['search', 'Search'];
        }
        if (str_contains($n, 'DISPLAY')) {
            return ['display', 'Display'];
        }
        if (str_contains($n, 'YOUTUBE') || str_contains($n, 'VIDEO')) {
            return ['video', 'Video'];
        }
        if (str_contains($n, 'DEMAND')) {
            return ['demandgen', 'Demand Gen'];
        }

        return ['other', 'Other'];
    }

    /**
     * Per-campaign Google rows (spend/revenue/purch/impr/clicks) summed over the
     * window — the shared source for the channel-mix and brand-split folds. Reads
     * ad_campaign_daily_metrics[google], already synced.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function googleCampaignRows(int $brandId, string $start, string $end, callable $money): \Illuminate\Support\Collection
    {
        return AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'google')
            ->whereBetween('date', [$start, $end])
            ->groupBy('campaign_id')
            ->selectRaw(
                'campaign_id,
                 MAX(campaign_name) AS campaign_name,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get();
    }

    /**
     * Google brand-vs-non-brand split — the incrementality lens. Folds campaigns
     * into Brand / Non-brand / Performance Max (mixed) by name. This is the single
     * most important Google cut for a DTC agency: brand campaigns capture a huge
     * share of REVENUE on a small share of SPEND because they largely harvest
     * demand that would convert anyway (a "121×" brand ROAS is not incremental).
     * PMax is kept separate because it blends brand + prospecting and can't be
     * cleanly attributed to either. Deterministic — no margin/LLM assumptions; the
     * UI carries the incrementality caveat.
     */
    private function brandSplit(int $brandId, string $start, string $end, callable $money): array
    {
        $seg = [
            'brand'    => ['key' => 'brand', 'label' => 'Brand', 'spend' => 0.0, 'revenue' => 0.0, 'purchases' => 0, 'impressions' => 0, 'clicks' => 0],
            'nonbrand' => ['key' => 'nonbrand', 'label' => 'Non-brand', 'spend' => 0.0, 'revenue' => 0.0, 'purchases' => 0, 'impressions' => 0, 'clicks' => 0],
            'pmax'     => ['key' => 'pmax', 'label' => 'Performance Max (mixed)', 'spend' => 0.0, 'revenue' => 0.0, 'purchases' => 0, 'impressions' => 0, 'clicks' => 0],
        ];

        foreach ($this->googleCampaignRows($brandId, $start, $end, $money) as $r) {
            $name = (string) $r->campaign_name;
            $n    = strtoupper($name);
            $k    = str_contains($n, 'PMAX') || str_contains($n, 'PERFORMANCE MAX') || str_contains($n, 'PERFORMANCE_MAX')
                ? 'pmax'
                : ($this->isBrandCampaign($n) ? 'brand' : 'nonbrand');

            $seg[$k]['spend']       += (float) $r->spend;
            $seg[$k]['revenue']     += (float) $r->revenue;
            $seg[$k]['purchases']   += (int) $r->purchases;
            $seg[$k]['impressions'] += (int) $r->impressions;
            $seg[$k]['clicks']      += (int) $r->clicks;
        }

        // Drop buckets with no delivery so a Search-only account shows 2 rows, not 3.
        $rows = array_values(array_filter($seg, static fn ($s) => $s['spend'] > 0 || $s['revenue'] > 0));

        return $this->packRows($rows);
    }

    /**
     * A campaign is "brand" when its name carries a brand token (BRAND / PURE) but
     * NOT a generic/non-brand token — "BRAND-GENERIC" and "NON-BRAND" both contain
     * BRAND yet are non-brand, so those are excluded first.
     */
    private function isBrandCampaign(string $upperName): bool
    {
        if (str_contains($upperName, 'GENERIC') || str_contains($upperName, 'NONBRAND') || str_contains($upperName, 'NON-BRAND')) {
            return false;
        }

        return str_contains($upperName, 'BRAND') || str_contains($upperName, 'PURE');
    }

    /**
     * Shape a set of pre-summed segment rows into the breakdown payload: derived
     * ratios + each segment's SHARE of window spend (pct), sorted by spend, capped.
     * Shared by every breakdown (country / age×gender / gender / age / placement /
     * device) so they all carry the same fields and a percentage.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{hasData: bool, top: ?array<string, mixed>, total: float, rows: array<int, array<string, mixed>>}
     */
    private function packRows(array $rows): array
    {
        if ($rows === []) {
            return ['hasData' => false, 'top' => null, 'total' => 0.0, 'rows' => []];
        }

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) $r['spend'];
        }

        $mapped = collect($rows)->map(static function ($r) use ($total) {
            $spend = round((float) $r['spend'], 2);
            $rev   = round((float) $r['revenue'], 2);
            $purch = (int) $r['purchases'];
            $impr  = (int) $r['impressions'];
            $clk   = (int) $r['clicks'];

            return [
                'key'         => (string) $r['key'],
                'label'       => (string) ($r['label'] !== '' ? $r['label'] : $r['key']),
                'spend'       => $spend,
                'revenue'     => $rev,
                'purchases'   => $purch,
                'impressions' => $impr,
                'clicks'      => $clk,
                'roas'        => $spend > 0 ? round($rev / $spend, 2) : null,
                'cpa'         => $purch > 0 ? round($spend / $purch, 2) : null,
                'ctr'         => $impr > 0 ? round($clk / $impr * 100, 2) : null,
                'pct'         => $total > 0 ? round($spend / $total * 100, 1) : 0.0,
            ];
        })->sortByDesc('spend')->values();

        return [
            'hasData' => true,
            'top'     => $mapped->first(),
            'total'   => round($total, 2),
            'rows'    => $mapped->take(self::MAX_COUNTRY_ROWS)->all(),
        ];
    }

    /**
     * age_gender is stored as "AGE · GENDER" (config meta_breakdowns → ['age',
     * 'gender']). Fetch it ONCE and fold it three ways for the Audience tab: the
     * raw age×gender combos, a male/female/unknown split, and an age split — so we
     * don't hit the table three times.
     *
     * @return array{ageGender: array<string,mixed>, gender: array<string,mixed>, age: array<string,mixed>}
     */
    private function ageGenderBreakdowns(int $brandId, string $start, string $end, callable $money, string $platform = 'meta'): array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('breakdown_type', 'age_gender')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key', 'segment_label')
            ->selectRaw(
                'segment_key, segment_label,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get();

        if ($rows->isEmpty()) {
            $na = ['hasData' => false, 'top' => null, 'total' => 0.0, 'rows' => []];

            return ['ageGender' => $na, 'gender' => $na, 'age' => $na];
        }

        $ageGender = $this->packRows($rows->map(static fn ($r) => [
            'key'         => (string) $r->segment_key,
            'label'       => (string) ($r->segment_label ?: $r->segment_key),
            'spend'       => (float) $r->spend,
            'revenue'     => (float) $r->revenue,
            'purchases'   => (int) $r->purchases,
            'impressions' => (int) $r->impressions,
            'clicks'      => (int) $r->clicks,
        ])->all());

        // Fold the combos into a single axis (gender or age) by parsing the key.
        $fold = function (callable $keyer, callable $labeler) use ($rows): array {
            $acc = [];
            foreach ($rows as $r) {
                $k = $keyer((string) $r->segment_key);
                $acc[$k] ??= ['key' => $k, 'label' => $labeler($k), 'spend' => 0.0, 'revenue' => 0.0, 'purchases' => 0, 'impressions' => 0, 'clicks' => 0];
                $acc[$k]['spend']       += (float) $r->spend;
                $acc[$k]['revenue']     += (float) $r->revenue;
                $acc[$k]['purchases']   += (int) $r->purchases;
                $acc[$k]['impressions'] += (int) $r->impressions;
                $acc[$k]['clicks']      += (int) $r->clicks;
            }

            return $this->packRows(array_values($acc));
        };

        $genderOf = static function (string $seg): string {
            $parts = array_map('trim', explode('·', $seg));
            $g     = strtolower((string) end($parts));
            if (str_contains($g, 'female')) return 'female';
            if (str_contains($g, 'male'))   return 'male';

            return 'unknown';
        };
        $ageOf = static function (string $seg): string {
            $a = strtolower(trim((string) (array_map('trim', explode('·', $seg))[0] ?? '')));
            // Guard the rare gender-only row so a gender word never lands as an age.
            if ($a === '' || in_array($a, ['female', 'male', 'unknown'], true)) return 'Unknown';

            return $a;
        };

        return [
            'ageGender' => $ageGender,
            'gender'    => $fold($genderOf, static fn ($k) => ucfirst($k)),
            'age'       => $fold($ageOf, static fn ($k) => $k === 'unknown' ? 'Unknown' : $k),
        ];
    }

    /**
     * Region rollup — the stored country breakdown grouped into regions (Europe,
     * North America, …) via the country_regions config. Derived from data we
     * already have (no separate `region` backfill needed); unmapped codes fold
     * into "Other" so regions reconcile to 100%.
     *
     * @return array{hasData: bool, top: ?array<string, mixed>, total: float, rows: array<int, array<string, mixed>>}
     */
    private function regionRollup(int $brandId, string $start, string $end, callable $money, string $platform = 'meta'): array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('breakdown_type', 'country')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key', 'segment_label')
            ->selectRaw(
                'segment_key,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get();

        if ($rows->isEmpty()) {
            return ['hasData' => false, 'top' => null, 'total' => 0.0, 'rows' => []];
        }

        $acc = [];
        foreach ($rows as $r) {
            $region = $this->countryRegion((string) $r->segment_key);
            $acc[$region] ??= ['key' => $region, 'label' => $region, 'spend' => 0.0, 'revenue' => 0.0, 'purchases' => 0, 'impressions' => 0, 'clicks' => 0];
            $acc[$region]['spend']       += (float) $r->spend;
            $acc[$region]['revenue']     += (float) $r->revenue;
            $acc[$region]['purchases']   += (int) $r->purchases;
            $acc[$region]['impressions'] += (int) $r->impressions;
            $acc[$region]['clicks']      += (int) $r->clicks;
        }

        return $this->packRows(array_values($acc));
    }

    /**
     * TikTok-native engagement (video completion + social) summed from
     * daily_metrics.metadata['tiktok'] over the window. Null when nothing synced
     * (→ the UI hides the panel). Metadata is cast to array on the model.
     *
     * @return array<string, mixed>|null
     */
    private function tiktokNative(int $brandId, string $start, string $end): ?array
    {
        $metas = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'tiktok')
            ->whereBetween('date', [$start, $end])
            ->pluck('metadata');

        $sum = [];
        foreach ($metas as $meta) {
            $native = is_array($meta) ? ($meta['tiktok'] ?? null) : null;
            if (! is_array($native)) {
                continue;
            }
            foreach ($native as $k => $v) {
                if (is_numeric($v)) {
                    $sum[(string) $k] = ($sum[(string) $k] ?? 0) + (float) $v;
                }
            }
        }

        if ($sum === []) {
            return null;
        }

        $plays = (float) ($sum['video_play_actions'] ?? 0);
        $g     = static fn (string $k): int => (int) round((float) ($sum[$k] ?? 0));

        return [
            'hasData' => true,
            'video'   => [
                'plays'          => $g('video_play_actions'),
                'watched2s'      => $g('video_watched_2s'),
                'watched6s'      => $g('video_watched_6s'),
                'p25'            => $g('video_views_p25'),
                'p50'            => $g('video_views_p50'),
                'p75'            => $g('video_views_p75'),
                'p100'           => $g('video_views_p100'),
                'completionRate' => $plays > 0 ? round(((float) ($sum['video_views_p100'] ?? 0)) / $plays * 100, 1) : null,
            ],
            'social'  => [
                'likes'         => $g('likes'),
                'comments'      => $g('comments'),
                'shares'        => $g('shares'),
                'follows'       => $g('follows'),
                'profileVisits' => $g('profile_visits'),
            ],
        ];
    }

    /**
     * Meta-native engagement (video completion + social) summed from
     * daily_metrics.metadata['meta'] over the window — the Meta twin of
     * tiktokNative(). Null when nothing synced (→ the UI hides the panel). Meta
     * has no 6-sec metric (ThruPlay is the deep-watch signal) and reports no ad
     * profile-visit count, so those two TikTok fields are absent by design;
     * "follows" maps to Page likes.
     *
     * @return array<string, mixed>|null
     */
    private function metaNative(int $brandId, string $start, string $end): ?array
    {
        $metas = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->whereBetween('date', [$start, $end])
            ->pluck('metadata');

        $sum = [];
        foreach ($metas as $meta) {
            $native = is_array($meta) ? ($meta['meta'] ?? null) : null;
            if (! is_array($native)) {
                continue;
            }
            foreach ($native as $k => $v) {
                if (is_numeric($v)) {
                    $sum[(string) $k] = ($sum[(string) $k] ?? 0) + (float) $v;
                }
            }
        }

        if ($sum === []) {
            return null;
        }

        $plays = (float) ($sum['video_play_actions'] ?? 0);
        $g     = static fn (string $k): int => (int) round((float) ($sum[$k] ?? 0));

        return [
            'hasData' => true,
            'video'   => [
                'plays'          => $g('video_play_actions'),
                'watched3s'      => $g('video_3s'),
                'thruplays'      => $g('thruplays'),
                'p25'            => $g('video_p25'),
                'p50'            => $g('video_p50'),
                'p75'            => $g('video_p75'),
                'p100'           => $g('video_p100'),
                'completionRate' => $plays > 0 ? round(((float) ($sum['video_p100'] ?? 0)) / $plays * 100, 1) : null,
            ],
            'social'  => [
                'likes'     => $g('likes'),
                'comments'  => $g('comments'),
                'shares'    => $g('shares'),
                'pageLikes' => $g('page_likes'),
            ],
        ];
    }

    /** Map an ISO-2 country code to its region label (country_regions config). */
    private function countryRegion(string $code): string
    {
        $labels = (array) config('country_regions.labels', []);
        $map    = (array) config('country_regions.map', []);
        $key    = $map[strtoupper(trim($code))] ?? 'other';

        return (string) ($labels[$key] ?? 'Other');
    }

    /**
     * Device split by attributed purchases (the donut). Empty until the `device`
     * breakdown has been backfilled.
     *
     * @return array{hasData: bool, metric: string, total: int, rows: array<int, array<string, mixed>>}
     */
    private function deviceSplit(int $brandId, string $start, string $end, callable $money, string $platform = 'meta'): array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('breakdown_type', 'device')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key', 'segment_label')
            ->selectRaw(
                'segment_key, segment_label,
                 COALESCE(SUM(conversions), 0)            AS purchases,
                 COALESCE(SUM(' . $money('spend') . '), 0) AS spend'
            )
            ->get();

        if ($rows->isEmpty()) {
            return ['hasData' => false, 'metric' => 'purchases', 'total' => 0, 'rows' => []];
        }

        $total = (int) $rows->sum(static fn ($r) => (int) $r->purchases);

        $mapped = $rows->map(static fn ($r) => [
            'label' => (string) ($r->segment_label ?: $r->segment_key),
            'value' => (int) $r->purchases,
            'pct'   => $total > 0 ? round((int) $r->purchases / $total * 100, 2) : 0.0,
        ])->sortByDesc('value')->values()->all();

        return ['hasData' => true, 'metric' => 'purchases', 'total' => $total, 'rows' => $mapped];
    }

    /**
     * Campaign table from ad_campaign_daily_metrics: each campaign summed over the
     * window with derived ratios and a prior-window impressions delta. Ranked by
     * spend desc (biggest first). Powers the Phase B drill-down later.
     *
     * @return array<int, array<string, mixed>>
     */
    private function campaigns(int $brandId, string $platform, string $start, string $end, string $priorStart, string $priorEnd, callable $money): array
    {
        $agg = fn (string $s, string $e) => AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$s, $e])
            ->groupBy('campaign_id')
            ->selectRaw(
                'campaign_id,
                 MAX(campaign_name) AS campaign_name,
                 MAX(status)        AS status,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get()
            ->keyBy('campaign_id');

        $cur  = $agg($start, $end);
        $prev = $agg($priorStart, $priorEnd);

        return $cur->map(function ($r) use ($prev) {
            $spend = round((float) $r->spend, 2);
            $rev   = round((float) $r->revenue, 2);
            $purch = (int) $r->purchases;
            $impr  = (int) $r->impressions;
            $clk   = (int) $r->clicks;
            $priorImpr = (int) ($prev[$r->campaign_id]->impressions ?? 0);

            return [
                'id'               => (string) $r->campaign_id,
                'name'             => (string) ($r->campaign_name ?: $r->campaign_id),
                'status'           => $r->status ? (string) $r->status : null,
                'spend'            => $spend,
                'revenue'          => $rev,
                'purchases'        => $purch,
                'impressions'      => $impr,
                'clicks'           => $clk,
                'roas'             => $spend > 0 ? round($rev / $spend, 2) : null,
                'cpa'              => $purch > 0 ? round($spend / $purch, 2) : null,
                'ctr'              => $impr > 0 ? round($clk / $impr * 100, 2) : null,
                'deltaImpressions' => $this->pctDelta((float) $priorImpr, (float) $impr),
            ];
        })->sortByDesc('spend')->values()->all();
    }

    /**
     * One campaign's detail for the drill-down drawer: KPI summary (+ prior-window
     * deltas) and a daily trend, from ad_campaign_daily_metrics scoped to the
     * campaign. reach/frequency are null here — those live at the account level
     * (daily_metrics), not per campaign — so the shape still matches the summary.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function campaignDetail(Brand $brand, string $campaignId, array $params): array
    {
        $tz       = $brand->timezone ?: 'UTC';
        $usd      = strtoupper((string) ($params['currency'] ?? '')) === 'USD';
        $platform = $this->resolvePlatform($params);

        [$start, $end]           = $this->window($params, $tz);
        [$priorStart, $priorEnd] = $this->priorWindow($start, $end);

        $money = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;

        $agg = fn (string $s, string $e) => AdCampaignDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $platform)
            ->where('campaign_id', $campaignId)
            ->whereBetween('date', [$s, $e])
            ->selectRaw(
                'MAX(campaign_name) AS campaign_name,
                 MAX(status)        AS status,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->first();

        $cur     = $agg($start, $end);
        $metrics = $this->derive($cur);
        $prior   = $this->derive($agg($priorStart, $priorEnd));

        $delta = [];
        foreach (['spend', 'revenue', 'purchases', 'roas', 'cpa', 'aov', 'cpm', 'cpc', 'ctr', 'impressions', 'clicks'] as $k) {
            $delta[$k] = $this->pctDelta((float) ($prior[$k] ?? 0), (float) ($metrics[$k] ?? 0));
        }
        $metrics['delta']     = $delta;
        $metrics['reach']     = null;
        $metrics['frequency'] = null;

        $trend = AdCampaignDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $platform)
            ->where('campaign_id', $campaignId)
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw(
                'date,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get()
            ->map(static fn ($r) => [
                'date'        => CarbonImmutable::parse((string) $r->date)->toDateString(),
                'spend'       => round((float) $r->spend, 2),
                'revenue'     => round((float) $r->revenue, 2),
                'purchases'   => (int) $r->purchases,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
            ])
            ->all();

        return [
            'campaign' => [
                'id'     => $campaignId,
                'name'   => (string) ($cur->campaign_name ?: $campaignId),
                'status' => $cur->status ? (string) $cur->status : null,
            ],
            'period'   => strtolower((string) ($params['period'] ?? 'last30')),
            'from'     => $start,
            'to'       => $end,
            'currency' => $usd ? 'usd' : 'native',
            'brand'    => ['baseCurrency' => $brand->base_currency],
            'summary'  => $metrics,
            'trend'    => $trend,
        ];
    }

    /**
     * Top creatives (Phase D) — ad-level rows from ad_creative_daily, summed over
     * the window per ad, ranked by spend, with a thumbnail. Empty (hasData=false)
     * until `meta:backfill-creatives` has run — the tab then shows "not synced",
     * never a fake €0.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function creatives(Brand $brand, array $params): array
    {
        $tz       = $brand->timezone ?: 'UTC';
        $usd      = strtoupper((string) ($params['currency'] ?? '')) === 'USD';
        $platform = $this->resolvePlatform($params);

        [$start, $end]   = $this->window($params, $tz);
        [$pStart, $pEnd] = $this->priorWindow($start, $end);
        $money = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;

        $base = [
            'from'         => $start,
            'to'           => $end,
            'currency'     => $usd ? 'usd' : 'native',
            'baseCurrency' => $brand->base_currency,
        ];

        $rows = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('ad_id')
            ->selectRaw(
                'ad_id,
                 MAX(ad_name)       AS ad_name,
                 MAX(campaign_id)   AS campaign_id,
                 MAX(thumbnail_url) AS thumbnail_url,
                 MAX(media_type)    AS media_type,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks,
                 COALESCE(SUM(video_3s), 0)                           AS video_3s,
                 COALESCE(SUM(thruplays), 0)                          AS thruplays,
                 COALESCE(SUM(add_to_cart), 0)                        AS add_to_cart"
            )
            ->get();

        if ($rows->isEmpty()) {
            return $base + ['hasData' => false, 'count' => 0, 'totalSpend' => 0.0, 'trend' => [], 'rows' => []];
        }

        // Prior equal-length window, spend per ad — the baseline for WoW% and the
        // blended state. One grouped query, keyed by ad for O(1) lookup.
        $prior = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $platform)
            ->whereBetween('date', [$pStart, $pEnd])
            ->groupBy('ad_id')
            ->selectRaw('ad_id, COALESCE(SUM(' . $money('spend') . '), 0) AS spend')
            ->get()
            ->keyBy('ad_id');

        // Weighted account ROAS over the window is the yardstick for "strong" vs
        // "weak" per-ad ROAS in the blended state rule.
        $totalSpend = round((float) $rows->sum(static fn ($r) => (float) $r->spend), 2);
        $totalRev   = (float) $rows->sum(static fn ($r) => (float) $r->revenue);
        $wRoas      = $totalSpend > 0 ? $totalRev / $totalSpend : 0.0;

        $mapped = $rows->map(function ($r) use ($prior, $totalSpend, $wRoas) {
            $spend   = round((float) $r->spend, 2);
            $rev     = round((float) $r->revenue, 2);
            $purch   = (int) $r->purchases;
            $impr    = (int) $r->impressions;
            $clk     = (int) $r->clicks;
            $v3s     = (int) $r->video_3s;
            $tp      = (int) $r->thruplays;
            $atc     = (int) $r->add_to_cart;
            $isVideo = ((string) $r->media_type) === 'video';

            $roas   = $spend > 0 ? $rev / $spend : null;
            $pSpend = ($p = $prior->get($r->ad_id)) ? round((float) $p->spend, 2) : 0.0;
            $wow    = $pSpend > 0 ? ($spend - $pSpend) / $pSpend : null;
            $share  = $totalSpend > 0 ? $spend / $totalSpend : 0.0;

            $roasStrong = $roas !== null && $wRoas > 0 && $roas >= 1.30 * $wRoas;
            $roasWeak   = $roas === null || ($wRoas > 0 ? $roas < 0.90 * $wRoas : $roas < 1.0);
            $state      = $this->creativeState($pSpend, $wow, $share, $roasStrong, $roasWeak);

            return [
                'adId'        => (string) $r->ad_id,
                'name'        => (string) ($r->ad_name ?: $r->ad_id),
                'campaignId'  => $r->campaign_id ? (string) $r->campaign_id : null,
                'thumbnail'   => $r->thumbnail_url ? (string) $r->thumbnail_url : null,
                'mediaType'   => $isVideo ? 'video' : 'image',
                'state'       => $state,
                'wow'         => $wow === null ? null : round($wow * 100, 1),
                'spend'       => $spend,
                'revenue'     => $rev,
                'purchases'   => $purch,
                'impressions' => $impr,
                'clicks'      => $clk,
                'roas'        => $roas === null ? null : round($roas, 2),
                'cpa'         => $purch > 0 ? round($spend / $purch, 2) : null,
                'ctr'         => $impr > 0 ? round($clk / $impr * 100, 2) : null,
                // Video engagement (null for image): TS = 3-sec views / impressions,
                // HR = ThruPlays / impressions.
                'ts'          => ($isVideo && $impr > 0) ? round($v3s / $impr * 100, 2) : null,
                'hr'          => ($isVideo && $impr > 0) ? round($tp / $impr * 100, 2) : null,
                // Funnel efficiency (shown on image cards): CtP = purchases / clicks,
                // CtATC = add-to-cart / clicks.
                'ctp'         => $clk > 0 ? round($purch / $clk * 100, 2) : null,
                'ctatc'       => $clk > 0 ? round($atc / $clk * 100, 2) : null,
            ];
        })->sortByDesc('spend')->values()->take(200)->all();

        // Daily trend (all creatives summed) — powers the KPI-strip sparklines.
        $trend = AdCreativeDaily::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw(
                'date,
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get()
            ->map(static fn ($r) => [
                'date'        => $r->date->toDateString(),
                'spend'       => round((float) $r->spend, 2),
                'revenue'     => round((float) $r->revenue, 2),
                'purchases'   => (int) $r->purchases,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
            ])->all();

        return $base + [
            'hasData'    => true,
            'count'      => $rows->count(),
            'totalSpend' => $totalSpend,
            'trend'      => $trend,
            'rows'       => $mapped,
        ];
    }

    /**
     * Blended creative state (Kanwar's pick): WoW% is spend-based, but DECLINING
     * requires spend down AND weak ROAS, so a good ad that simply spent less isn't
     * alarmed. Priority: brand-new → under-scaled winner → scaling → declining →
     * holding.
     */
    private function creativeState(float $priorSpend, ?float $wow, float $spendShare, bool $roasStrong, bool $roasWeak): string
    {
        if ($priorSpend <= 0) {
            return 'testing';                                   // no prior spend — new this window
        }
        if ($spendShare < 0.005 && $roasStrong) {
            return 'hidden';                                    // strong ROAS but starved of budget
        }
        if ($wow !== null && $wow >= 0.20) {
            return 'scaling';                                   // budget up ≥ 20%
        }
        if ($wow !== null && $wow <= -0.20 && $roasWeak) {
            return 'declining';                                 // budget down ≥ 20% AND efficiency weak
        }

        return 'holding';
    }

    /**
     * [start, end] date strings in the brand's timezone. End is yesterday (today
     * is partial). Mirrors AudienceQuery windows; adds a `custom` from/to range.
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: string}
     */
    private function window(array $params, string $tz): array
    {
        $period = strtolower((string) ($params['period'] ?? 'last30'));
        $now    = CarbonImmutable::now($tz);
        $yest   = $now->subDay()->startOfDay();

        if ($period === 'custom' && ! empty($params['from']) && ! empty($params['to'])) {
            $from = CarbonImmutable::parse((string) $params['from'], $tz)->startOfDay();
            $to   = CarbonImmutable::parse((string) $params['to'], $tz)->startOfDay();
            if ($to->greaterThan($yest)) {
                $to = $yest;
            }
            if ($from->greaterThan($to)) {
                $from = $to;
            }

            return [$from->toDateString(), $to->toDateString()];
        }

        // Last month = the previous full calendar month (always complete, so its
        // end is the month's last day, not yesterday).
        if ($period === 'lastmonth') {
            $lm = $now->startOfMonth()->subMonth();

            return [$lm->toDateString(), $lm->endOfMonth()->toDateString()];
        }

        $start = match ($period) {
            'last7'  => $now->subDays(7)->startOfDay(),
            'last14' => $now->subDays(14)->startOfDay(),
            'mtd'    => $now->startOfMonth(),
            default  => $now->subDays(30)->startOfDay(),
        };
        if ($start->greaterThan($yest)) {
            $start = $yest;
        }

        return [$start->toDateString(), $yest->toDateString()];
    }

    /**
     * The window immediately before [start, end] of equal length — the baseline
     * for the % deltas.
     *
     * @return array{0: string, 1: string}
     */
    private function priorWindow(string $start, string $end): array
    {
        $s   = CarbonImmutable::parse($start);
        $e   = CarbonImmutable::parse($end);
        $len = (int) $s->diffInDays($e) + 1;

        $priorEnd   = $s->subDay();
        $priorStart = $priorEnd->subDays($len - 1);

        return [$priorStart->toDateString(), $priorEnd->toDateString()];
    }

    /** Validate the platform param; fall back to Meta. */
    private function resolvePlatform(array $params): string
    {
        $p = strtolower(trim((string) ($params['platform'] ?? '')));

        return in_array($p, ['meta', 'google', 'tiktok'], true) ? $p : self::PLATFORM;
    }

    /** Empty breakdown block for platforms whose breakdowns we don't store (non-Meta). */
    private function notApplicable(): array
    {
        return ['hasData' => false, 'top' => null, 'rows' => []];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
