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

        return [
            'brand' => [
                'id'           => (int) $brand->id,
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'initials'     => $this->initials((string) $brand->name),
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $tz,
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
            'byCountry'   => $isMeta ? $this->breakdown((int) $brand->id, 'country', $start, $end, $money) : $this->notApplicable(),
            'byDevice'    => $isMeta ? $this->deviceSplit((int) $brand->id, $start, $end, $money) : ['hasData' => false, 'metric' => 'purchases', 'total' => 0, 'rows' => []],
            'byAgeGender' => $isMeta ? $this->breakdown((int) $brand->id, 'age_gender', $start, $end, $money) : $this->notApplicable(),
            'byPlacement' => $isMeta ? $this->breakdown((int) $brand->id, 'placement_platform', $start, $end, $money) : $this->notApplicable(),
            'campaigns'   => $this->campaigns((int) $brand->id, $platform, $start, $end, $priorStart, $priorEnd, $money),
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

        return ['metrics' => $metrics, 'isComplete' => $isComplete, 'funnel' => $this->funnel($metrics, $cur)];
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
    private function funnel(array $m, object $cur): array
    {
        $lcN  = (int) ($cur->lc_n ?? 0);
        $lpvN = (int) ($cur->lpv_n ?? 0);

        return [
            ['key' => 'impressions',        'label' => 'Impressions',   'value' => (int) $m['impressions'],                          'pending' => false],
            ['key' => 'link_clicks',        'label' => 'Link clicks',   'value' => $lcN > 0 ? (int) $cur->link_clicks : null,        'pending' => $lcN === 0],
            ['key' => 'landing_page_views', 'label' => 'Landing views', 'value' => $lpvN > 0 ? (int) $cur->landing_page_views : null, 'pending' => $lpvN === 0],
            ['key' => 'purchases',          'label' => 'Purchases',     'value' => (int) $m['purchases'],                            'pending' => false],
        ];
    }

    /**
     * A stored breakdown axis (country) rolled up over the window: top segment +
     * ranked rows. Empty (hasData=false) until `meta:backfill-breakdown` has run
     * for this axis — the frontend then shows "not synced", not €0.
     *
     * @return array{hasData: bool, top: array<string, mixed>|null, rows: array<int, array<string, mixed>>}
     */
    private function breakdown(int $brandId, string $type, string $start, string $end, callable $money): array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
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

        if ($rows->isEmpty()) {
            return ['hasData' => false, 'top' => null, 'rows' => []];
        }

        $mapped = $rows->map(static function ($r) {
            $spend = round((float) $r->spend, 2);
            $rev   = round((float) $r->revenue, 2);
            $purch = (int) $r->purchases;

            return [
                'key'         => (string) $r->segment_key,
                'label'       => (string) ($r->segment_label ?: $r->segment_key),
                'spend'       => $spend,
                'revenue'     => $rev,
                'purchases'   => $purch,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
                'roas'        => $spend > 0 ? round($rev / $spend, 2) : null,
                'cpa'         => $purch > 0 ? round($spend / $purch, 2) : null,
            ];
        })->sortByDesc('spend')->values();

        return [
            'hasData' => true,
            'top'     => $mapped->first(),
            'rows'    => $mapped->take(self::MAX_COUNTRY_ROWS)->all(),
        ];
    }

    /**
     * Device split by attributed purchases (the donut). Empty until the `device`
     * breakdown has been backfilled.
     *
     * @return array{hasData: bool, metric: string, total: int, rows: array<int, array<string, mixed>>}
     */
    private function deviceSplit(int $brandId, string $start, string $end, callable $money): array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
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

        [$start, $end] = $this->window($params, $tz);
        $money = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;

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
                 COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks"
            )
            ->get();

        $base = [
            'from'         => $start,
            'to'           => $end,
            'currency'     => $usd ? 'usd' : 'native',
            'baseCurrency' => $brand->base_currency,
        ];

        if ($rows->isEmpty()) {
            return $base + ['hasData' => false, 'rows' => []];
        }

        $mapped = $rows->map(static function ($r) {
            $spend = round((float) $r->spend, 2);
            $rev   = round((float) $r->revenue, 2);
            $purch = (int) $r->purchases;
            $impr  = (int) $r->impressions;
            $clk   = (int) $r->clicks;

            return [
                'adId'        => (string) $r->ad_id,
                'name'        => (string) ($r->ad_name ?: $r->ad_id),
                'campaignId'  => $r->campaign_id ? (string) $r->campaign_id : null,
                'thumbnail'   => $r->thumbnail_url ? (string) $r->thumbnail_url : null,
                'spend'       => $spend,
                'revenue'     => $rev,
                'purchases'   => $purch,
                'impressions' => $impr,
                'clicks'      => $clk,
                'roas'        => $spend > 0 ? round($rev / $spend, 2) : null,
                'cpa'         => $purch > 0 ? round($spend / $purch, 2) : null,
                'ctr'         => $impr > 0 ? round($clk / $impr * 100, 2) : null,
            ];
        })->sortByDesc('spend')->values()->take(40)->all();

        return $base + ['hasData' => true, 'rows' => $mapped];
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

        $start = match ($period) {
            'last7' => $now->subDays(7)->startOfDay(),
            'mtd'   => $now->startOfMonth(),
            default => $now->subDays(30)->startOfDay(),
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
