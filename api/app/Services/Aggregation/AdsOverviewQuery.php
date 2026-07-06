<?php

declare(strict_types=1);

namespace App\Services\Aggregation;

use App\Models\AdCampaignDailyMetric;
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

    /** Country/device tables are long-tailed — cap the rows we ship. */
    private const MAX_COUNTRY_ROWS = 12;

    /**
     * @param array<string, mixed> $params  period|from|to|currency
     * @return array<string, mixed>
     */
    public function run(Brand $brand, array $params): array
    {
        $tz  = $brand->timezone ?: 'UTC';
        $usd = strtoupper((string) ($params['currency'] ?? '')) === 'USD';

        [$start, $end]           = $this->window($params, $tz);
        [$priorStart, $priorEnd] = $this->priorWindow($start, $end);

        // Native by default; ×fx to USD when the currency toggle is on. Applied
        // to every money column so cross-currency views stay comparable.
        $money = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;

        $summary = $this->summary((int) $brand->id, $start, $end, $priorStart, $priorEnd, $money);

        return [
            'brand' => [
                'id'           => (int) $brand->id,
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'initials'     => $this->initials((string) $brand->name),
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $tz,
            ],
            'platform'   => self::PLATFORM,
            'period'     => strtolower((string) ($params['period'] ?? 'last30')),
            'from'       => $start,
            'to'         => $end,
            'currency'   => $usd ? 'usd' : 'native',
            'isComplete' => $summary['isComplete'],
            'summary'    => $summary['metrics'],
            'trend'      => $this->trend((int) $brand->id, $start, $end, $money),
            'funnel'     => $this->funnel($summary['metrics']),
            'byCountry'  => $this->breakdown((int) $brand->id, 'country', $start, $end, $money),
            'byDevice'   => $this->deviceSplit((int) $brand->id, $start, $end, $money),
            'campaigns'  => $this->campaigns((int) $brand->id, $start, $end, $priorStart, $priorEnd, $money),
        ];
    }

    /**
     * KPI block from daily_metrics + prior-window deltas + the freshness gate.
     *
     * @return array{metrics: array<string, mixed>, isComplete: bool}
     */
    private function summary(int $brandId, string $start, string $end, string $priorStart, string $priorEnd, callable $money): array
    {
        $agg = fn (string $s, string $e) => DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', self::PLATFORM)
            ->whereBetween('date', [$s, $e])
            ->selectRaw(
                'COALESCE(SUM(' . $money('spend') . "), 0)            AS spend,
                 COALESCE(SUM(" . $money('conversion_value') . "), 0) AS revenue,
                 COALESCE(SUM(conversions), 0)                        AS purchases,
                 COALESCE(SUM(impressions), 0)                        AS impressions,
                 COALESCE(SUM(clicks), 0)                             AS clicks,
                 SUM(CASE WHEN is_complete THEN 1 ELSE 0 END)         AS complete_days"
            )
            ->first();

        $metrics = $this->derive($agg($start, $end));
        $prior   = $this->derive($agg($priorStart, $priorEnd));

        $delta = [];
        foreach (['spend', 'revenue', 'purchases', 'roas', 'cpa', 'aov', 'cpm', 'cpc', 'ctr', 'impressions', 'clicks'] as $k) {
            $delta[$k] = $this->pctDelta((float) ($prior[$k] ?? 0), (float) ($metrics[$k] ?? 0));
        }
        $metrics['delta'] = $delta;

        // Freshness gate (Bosco, 2026-06-30): only "complete" when every day in the
        // window has a finalized Meta row — otherwise the sync hasn't fully run and
        // the frontend renders an amber "not synced", never a partial-window total.
        $cur          = $agg($start, $end);
        $expectedDays = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
        $isComplete   = $expectedDays > 0 && (int) ($cur->complete_days ?? 0) >= $expectedDays;

        return ['metrics' => $metrics, 'isComplete' => $isComplete];
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
    private function trend(int $brandId, string $start, string $end, callable $money): array
    {
        return DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', self::PLATFORM)
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
     * Purchase funnel. Impressions + Purchases are stored today; Link clicks and
     * Add to cart need the deferred Meta field additions (inline_link_clicks /
     * the add_to_cart action) — they render as `pending` until that ships, never
     * as a fake number.
     *
     * @param array<string, mixed> $m
     * @return array<int, array<string, mixed>>
     */
    private function funnel(array $m): array
    {
        return [
            ['key' => 'impressions', 'label' => 'Impressions', 'value' => (int) $m['impressions'], 'pending' => false],
            ['key' => 'link_clicks', 'label' => 'Link clicks', 'value' => null,                    'pending' => true],
            ['key' => 'add_to_cart', 'label' => 'Add to cart', 'value' => null,                    'pending' => true],
            ['key' => 'purchases',   'label' => 'Purchases',   'value' => (int) $m['purchases'],   'pending' => false],
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
    private function campaigns(int $brandId, string $start, string $end, string $priorStart, string $priorEnd, callable $money): array
    {
        $agg = fn (string $s, string $e) => AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', self::PLATFORM)
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

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
