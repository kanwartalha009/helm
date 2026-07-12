<?php

declare(strict_types=1);

namespace App\Reports\Weekly;

use App\Models\AdCampaignDailyMetric;
use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\EmailDailyMetric;
use App\Models\PlatformConnection;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Contracts\ReportType;
use App\Reports\Support\AdAudit;
use App\Services\AdsLibrary\MarketAlerts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Monday client email (spec §2 "Weekly ad report") — a compact one-to-two
 * page snapshot of the last COMPLETE Mon–Sun ISO week in the brand's timezone.
 * Like MonthlyReport, build() ignores the period filter: the report is
 * inherently "last week", compared against the week before and (when the brand
 * has rows that far back) the same calendar week one year earlier.
 *
 * Headline runs on data Helm already syncs: brand-total revenue (D-005:
 * Shopify total_sales with refunds added back), ad spend per platform, blended
 * ROAS, orders and AOV from daily_metrics; campaign movers from
 * ad_campaign_daily_metrics; the action plan reuses the AdAudit rules for the
 * week window. Missing ≠ zero throughout — an unconnected platform reports
 * connected:false and an unsynced/incomplete day renders null, never 0.
 */
final class WeeklyReport implements ReportType
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    public function __construct(
        private readonly AdAudit $ads,
        private readonly MarketAlerts $marketAlerts,
    ) {}

    public function key(): string
    {
        return 'weekly';
    }

    public function label(): string
    {
        return 'Weekly performance';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz  = $brand->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        // The last COMPLETE Mon–Sun ISO week: startOfWeek(Monday) is this week's
        // Monday (partial — never sent to a client), so step one week back. On a
        // Monday this correctly yields the week that ended yesterday (Sunday).
        // A ?week=YYYY-MM-DD (Monday) selector overrides it, but only for a
        // COMPLETE week (weekWindow returns null otherwise); the WoW and
        // same-week-last-year comparisons derive from $weekStart, so they shift
        // with the selection.
        $defaultWeekStart = $now->startOfWeek(CarbonInterface::MONDAY)->subWeek()->startOfDay();

        $selected  = $filters->weekWindow($tz);
        $weekStart = $selected !== null
            ? CarbonImmutable::parse($selected[0], $tz)->startOfDay()
            : $defaultWeekStart;
        $weekEnd   = $weekStart->addDays(6);
        $start     = $weekStart->toDateString();
        $end       = $weekEnd->toDateString();

        // Comparison windows: the week immediately before, and the same ISO week
        // last year (52 weeks back keeps the Mon–Sun alignment).
        $prevStart = $weekStart->subWeek()->toDateString();
        $prevEnd   = $weekEnd->subWeek()->toDateString();
        $lyStart   = $weekStart->subWeeks(52)->toDateString();
        $lyEnd     = $weekEnd->subWeeks(52)->toDateString();

        /** @var array<int, string> $connected */
        $connected = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        $cur  = $this->metrics($brand->id, $start, $end, $filters->usd);
        $prev = $this->metrics($brand->id, $prevStart, $prevEnd, $filters->usd);

        // Same week last year only when rows actually exist that far back —
        // otherwise the YoY comparison is null, never a fabricated 0.
        $hasLastYear = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$lyStart, $lyEnd])
            ->exists();
        $ly = $hasLastYear ? $this->metrics($brand->id, $lyStart, $lyEnd, $filters->usd) : null;

        $currency = $filters->usd ? 'USD' : ($brand->base_currency ?: 'USD');

        return [
            'reportType' => $this->key(),
            'brand' => [
                'name'         => $brand->name,
                'slug'         => $brand->slug,
                'baseCurrency' => $brand->base_currency,
                'timezone'     => $brand->timezone,
            ],
            'currency' => $currency,
            'week'     => [
                'label' => $weekStart->isoFormat('D MMM') . ' – ' . $weekEnd->isoFormat('D MMM YYYY'),
                'start' => $start,
                'end'   => $end,
            ],
            'comparison' => [
                'previous' => ['start' => $prevStart, 'end' => $prevEnd],
                'lastYear' => $hasLastYear ? ['start' => $lyStart, 'end' => $lyEnd] : null,
            ],
            // Week picker: complete Mon–Sun weeks the brand has Shopify rows
            // for (clamped to 8), most recent first. Fault-isolated.
            'availableWeeks' => $this->safely('availableWeeks', fn () => $this->availableWeeks($brand->id, $defaultWeekStart), []),
            'kpis' => [
                'totalRevenue' => $this->kpi('money', $cur['revenue'], $prev['revenue'], $ly['revenue'] ?? null),
                'adSpend'      => $this->kpi('money', $cur['totalSpend'], $prev['totalSpend'], $ly['totalSpend'] ?? null),
                'blendedRoas'  => $this->kpi('ratio', $cur['roas'], $prev['roas'], $ly['roas'] ?? null),
                'orders'       => $this->kpi('int', $cur['orders'], $prev['orders'], $ly['orders'] ?? null),
                'aov'          => $this->kpi('money', $cur['aov'], $prev['aov'], $ly['aov'] ?? null),
            ],
            // Each optional section is fault-isolated: a failure logs and drops to
            // a safe default instead of 500-ing the whole report.
            'dailySeries'     => $this->safely('dailySeries', fn () => $this->dailySeries($brand->id, $weekStart, $filters->usd), []),
            'spendByPlatform' => $this->byPlatform($cur, $connected),
            'spendComplete'   => count(array_intersect(self::AD_PLATFORMS, $connected)) === count(self::AD_PLATFORMS),
            'campaignMovers'  => $this->safely('campaignMovers', fn () => $this->campaignMovers($brand->id, $start, $end, $prevStart, $prevEnd, $filters->usd), []),
            'actions'         => $this->safely('actions', fn () => $this->actions($brand->id, $connected, $start, $end, $prevStart, $prevEnd, $filters->usd), []),
            // Competitor movement for this brand's niche (Ads Library Phase 5) —
            // Proxy signals from the tracked-page corpus, separate from `actions`
            // (which are our own verified ad verdicts). Empty for brands with no
            // niche set. Fault-isolated like every optional section.
            'marketAlerts'    => $this->safely('marketAlerts', fn () => $this->marketMoves($brand), []),
            // Klaviyo email revenue (GO-1.1) — its OWN channel. NULL (not 0) when the
            // brand has no Klaviyo data for the week. NEVER added to revenue/spend.
            'email'           => $this->safely('email', fn () => $this->emailBlock($brand, $start, $end, $filters->usd), null),
            // Is the data current through the week's Sunday? Same contract as the
            // overall-performance report — the SPA gates on it. On error, fail
            // CLOSED: gating a fresh report is annoying, un-gating a stale one
            // sends wrong numbers to a client.
            // fail CLOSED — a freshness bug must never un-gate a stale report.
            'freshness' => $this->safely('freshness', fn () => $this->freshness($brand->id, $end), [
                'upToDate' => false, 'lastSynced' => null, 'staleDays' => 0, 'windowEnd' => $end,
                'note'     => 'Freshness could not be verified — the report is held back until a sync confirms the data is current.',
            ]),
        ];
    }

    /**
     * Run an optional report section in isolation: on any failure, log it and
     * fall back to $default so one broken section never 500s the report.
     *
     * @param \Closure(): mixed $fn
     */
    private function safely(string $section, \Closure $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::warning('weekly_report.section_failed', [
                'section' => $section,
                'error'   => $e->getMessage(),
                'at'      => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $default;
        }
    }

    /**
     * The selectable report weeks: complete Mon–Sun weeks (most recent first)
     * where the brand has ANY Shopify daily_metrics rows, clamped to 8. Empty
     * (never fabricated weeks) when nothing is synced.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function availableWeeks(int $brandId, CarbonImmutable $lastCompleteWeekStart): array
    {
        $min = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->min('date');

        if ($min === null) {
            return [];
        }

        // Compare on the date STRING — the brand-tz week start and the tz-less
        // MIN(date) would otherwise disagree by a few hours.
        $minMonday = CarbonImmutable::parse((string) $min)->startOfWeek(CarbonInterface::MONDAY)->toDateString();

        $out = [];
        for ($w = $lastCompleteWeekStart; count($out) < 8 && $w->toDateString() >= $minMonday; $w = $w->subWeek()) {
            $out[] = [
                'key'   => $w->toDateString(),
                'label' => $w->isoFormat('ddd D') . ' – ' . $w->addDays(6)->isoFormat('ddd D MMM'),
            ];
        }

        return $out;
    }

    /**
     * The 7 days of the report week — revenue (D-005) and ad spend per day for
     * the bar chart. Missing ≠ zero: a day with no synced Shopify row, or one
     * still marked incomplete, renders null revenue (the SPA shows it hatched),
     * and a day with no ad rows renders null spend.
     *
     * @return array<int, array{date: string, revenue: ?float, spend: ?float, complete: bool}>
     */
    private function dailySeries(int $brandId, CarbonImmutable $weekStart, bool $usd): array
    {
        $disp   = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';
        $start  = $weekStart->toDateString();
        $end    = $weekStart->addDays(6)->toDateString();

        $shopify = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("date, {$disp($revCol)} AS revenue, is_complete")
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse((string) $r->date)->toDateString());

        $spend = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereIn('platform', self::AD_PLATFORMS)
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->selectRaw("date, COALESCE(SUM({$disp('spend')}), 0) AS spend")
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse((string) $r->date)->toDateString());

        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $d   = $weekStart->addDays($i)->toDateString();
            $row = $shopify->get($d);
            $complete = $row !== null && (bool) $row->is_complete;
            $out[] = [
                'date'     => $d,
                'revenue'  => $complete ? round((float) $row->revenue, 2) : null,
                'spend'    => $spend->has($d) ? round((float) $spend->get($d)->spend, 2) : null,
                'complete' => $complete,
            ];
        }

        return $out;
    }

    /**
     * Top campaigns by spend this week with week-over-week movement, across every
     * ad platform that has campaign rows (a platform without rows contributes
     * nothing — missing ≠ zero). ROAS is computed in USD so the ratio is correct
     * in either currency mode.
     *
     * @return array<int, array<string, mixed>>
     */
    private function campaignMovers(int $brandId, string $start, string $end, string $prevStart, string $prevEnd, bool $usd, int $limit = 8): array
    {
        $cur  = $this->campaignAggregates($brandId, $start, $end);
        if ($cur === []) {
            return [];
        }
        $prev = $this->campaignAggregates($brandId, $prevStart, $prevEnd);

        usort($cur, static fn (array $a, array $b): int => $b['spend_usd'] <=> $a['spend_usd']);
        $cur = array_slice($cur, 0, $limit);

        $out = [];
        foreach ($cur as $c) {
            $p        = $prev[$c['key']] ?? null;
            $roas     = $c['spend_usd'] > 0 ? round($c['value_usd'] / $c['spend_usd'], 2) : null;
            $prevRoas = $p !== null && $p['spend_usd'] > 0 ? round($p['value_usd'] / $p['spend_usd'], 2) : null;
            $out[] = [
                'platform'   => $c['platform'],
                'id'         => $c['campaign_id'],
                'name'       => $c['name'] !== '' ? $c['name'] : $c['campaign_id'],
                'spend'      => round($usd ? $c['spend_usd'] : $c['spend_native'], 2),
                'revenue'    => round($usd ? $c['value_usd'] : $c['value_native'], 2),
                'roas'       => $roas,
                'prevSpend'  => $p !== null ? round($usd ? $p['spend_usd'] : $p['spend_native'], 2) : null,
                'spendDelta' => $this->pct($c['spend_usd'], $p['spend_usd'] ?? null),
                'prevRoas'   => $prevRoas,
                'roasDelta'  => ($roas !== null && $prevRoas !== null) ? round($roas - $prevRoas, 2) : null,
            ];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> keyed by platform|campaign_id */
    private function campaignAggregates(int $brandId, string $start, string $end): array
    {
        $rows = AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereBetween('date', [$start, $end])
            ->groupBy('platform', 'campaign_id')
            ->selectRaw('platform, campaign_id,
                MAX(campaign_name) AS name,
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value) AS value_native,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = $r->platform . '|' . $r->campaign_id;
            $out[$key] = [
                'key'          => $key,
                'platform'     => (string) $r->platform,
                'campaign_id'  => (string) $r->campaign_id,
                'name'         => (string) ($r->name ?? ''),
                'spend_native' => (float) $r->spend_native,
                'spend_usd'    => (float) $r->spend_usd,
                'value_native' => (float) $r->value_native,
                'value_usd'    => (float) $r->value_usd,
            ];
        }

        return $out;
    }

    /**
     * The week's action plan — the AdAudit rules run for the week window per
     * connected ad platform, their action lists merged (tagged with the platform
     * so the card can say where). Platforms without campaign rows contribute
     * nothing.
     *
     * @param array<int, string> $connected
     * @return array<int, array<string, mixed>>
     */
    private function actions(int $brandId, array $connected, string $start, string $end, string $prevStart, string $prevEnd, bool $usd): array
    {
        $out = [];
        foreach (self::AD_PLATFORMS as $platform) {
            if (! in_array($platform, $connected, true)) {
                continue;
            }
            $audit = $this->ads->forPlatform($brandId, $platform, $start, $end, $prevStart, $prevEnd, $usd);
            foreach ($audit['actions'] ?? [] as $action) {
                $action['platform'] = $platform;
                $out[] = $action;
            }
        }

        return $out;
    }

    /**
     * Klaviyo email revenue for the week (GO-1.1) — its OWN channel, never summed
     * into store or ad revenue (§0.1 honesty law: Klaviyo is last-touch within its
     * own windows and OVERLAPS ad + organic revenue). `shareOfStore` is a RATIO of
     * two measured numbers, not an additive split — the honesty box says so verbatim.
     *
     * Returns null (never a 0 block) when the brand has no Klaviyo rows for the week —
     * missing ≠ zero. Money follows the report's usd/native mode like every other section.
     *
     * @return array<string, mixed>|null
     */
    private function emailBlock(Brand $brand, string $start, string $end, bool $usd): ?array
    {
        $disp = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;

        $base = EmailDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$start, $end]);

        if (! (clone $base)->exists()) {
            return null; // no Klaviyo data this week → "—", never €0
        }

        $totals = (clone $base)
            ->selectRaw("COALESCE(SUM({$disp('conversion_value')}), 0) AS revenue, COALESCE(SUM(conversions), 0) AS orders")
            ->first();

        $revenue = round((float) ($totals->revenue ?? 0), 2);
        $orders  = (int) ($totals->orders ?? 0);

        // Store revenue for the same week, same currency mode (D-005 basis) — used
        // ONLY for the share ratio, never to build a combined "total".
        $storeRev = (float) DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COALESCE(SUM(' . $disp('(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))') . '), 0) AS v')
            ->value('v');

        $top = (clone $base)
            ->groupBy('source', 'source_id')
            ->selectRaw("source, source_id, MAX(source_name) AS name,
                COALESCE(SUM({$disp('conversion_value')}), 0) AS revenue,
                COALESCE(SUM(conversions), 0) AS orders")
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(static fn ($r): array => [
                'source'  => (string) $r->source,     // flow | campaign
                'id'      => (string) $r->source_id,
                'name'    => $r->name !== null && $r->name !== '' ? (string) $r->name : null,
                'revenue' => round((float) $r->revenue, 2),
                'orders'  => (int) $r->orders,
            ])
            ->all();

        return [
            'revenue'      => $revenue,
            'orders'       => $orders,
            // Ratio, not a share of a sum. Null when there's no store revenue to compare.
            'shareOfStore' => $storeRev > 0.0 ? round($revenue / $storeRev * 100, 1) : null,
            'topSources'   => $top,
            'label'        => 'Verified — Klaviyo-attributed',
            'honestyBox'   => (string) config('klaviyo.honesty_box'),
        ];
    }

    /**
     * Competitor movement for the brand's niche this week — Proxy signals only
     * (public Ad Library corpus), never blended into performance. Empty when the
     * brand has no niche set or no tracked pages have moved.
     *
     * @return array<int, array{type: string, severity: string, message: string, pageName: ?string}>
     */
    private function marketMoves(Brand $brand): array
    {
        if (($brand->niche ?? '') === '') {
            return [];
        }

        $out = [];
        foreach ($this->marketAlerts->forPages($brand->niche) as $a) {
            $out[] = [
                'type'     => $a['type'],
                'severity' => $a['severity'],
                'message'  => $a['message'],
                'pageName' => $a['pageName'],
            ];
        }

        return $out;
    }

    /**
     * Is the report's data current? Compares the latest COMPLETE Shopify day on
     * file against the window end (the week's Sunday). Same contract as
     * OverallPerformanceReport::freshness — the SPA blocks a stale report behind
     * a "sync fresh data first" gate.
     *
     * @return array<string, mixed>
     */
    private function freshness(int $brandId, string $windowEnd): array
    {
        $lastComplete = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->max('date');

        $end  = CarbonImmutable::parse($windowEnd)->startOfDay();
        $last = $lastComplete !== null ? CarbonImmutable::parse((string) $lastComplete)->startOfDay() : null;

        return [
            'upToDate'   => $last !== null && $last->greaterThanOrEqualTo($end),
            'lastSynced' => $last?->toDateString(),
            'staleDays'  => ($last !== null && $last->lessThan($end)) ? (int) $last->diffInDays($end) : 0,
            'windowEnd'  => $end->toDateString(),
        ];
    }

    /**
     * Week totals in display currency. Mirrors the overall-performance report:
     * revenue = Shopify total_sales with refunds added back (D-005); spend summed
     * across ad platforms; ROAS computed in USD so the ratio is correct in either
     * currency mode; AOV null (never 0) when there are no orders.
     *
     * @return array<string, mixed>
     */
    private function metrics(int $brandId, string $start, string $end, bool $usd): array
    {
        $disp = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;
        $usdc = static fn (string $col): string => "{$col} * COALESCE(fx_rate_to_usd, 1)";
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';

        $c = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("
                COALESCE(SUM({$disp($revCol)}), 0) AS revenue,
                COALESCE(SUM({$usdc($revCol)}), 0) AS revenue_usd,
                COALESCE(SUM(orders), 0)           AS orders
            ")
            ->first();

        $revenue    = (float) ($c->revenue ?? 0);
        $revenueUsd = (float) ($c->revenue_usd ?? 0);
        $orders     = (int) ($c->orders ?? 0);

        $spendByPlatform = [];
        $totalSpend      = 0.0;
        $totalSpendUsd   = 0.0;
        foreach (self::AD_PLATFORMS as $p) {
            $s = DailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('platform', $p)
                ->whereBetween('date', [$start, $end])
                ->selectRaw("COALESCE(SUM({$disp('spend')}), 0) AS spend, COALESCE(SUM({$usdc('spend')}), 0) AS spend_usd")
                ->first();
            $sp = round((float) ($s->spend ?? 0), 2);

            $spendByPlatform[$p] = $sp;
            $totalSpend    += $sp;
            $totalSpendUsd += (float) ($s->spend_usd ?? 0);
        }

        return [
            'revenue'         => round($revenue, 2),
            'orders'          => $orders,
            'aov'             => $orders > 0 ? round($revenue / $orders, 2) : null,
            'spendByPlatform' => $spendByPlatform,
            'totalSpend'      => round($totalSpend, 2),
            'roas'            => $totalSpendUsd > 0.0 ? round($revenueUsd / $totalSpendUsd, 2) : null,
        ];
    }

    /**
     * @param array<string, mixed> $cur
     * @param array<int, string> $connected
     * @return array<int, array<string, mixed>>
     */
    private function byPlatform(array $cur, array $connected): array
    {
        $out = [];
        foreach (self::AD_PLATFORMS as $p) {
            $isConnected = in_array($p, $connected, true);
            $out[] = [
                'platform'  => $p,
                'connected' => $isConnected,
                'spend'     => $isConnected ? ($cur['spendByPlatform'][$p] ?? 0.0) : null,
            ];
        }

        return $out;
    }

    /**
     * KPI with WoW delta plus the same-week-last-year value (null when the brand
     * has no rows that far back). Ratio KPIs carry absolute deltas, others %.
     *
     * @return array<string, float|int|null>
     */
    private function kpi(string $kind, float|int|null $value, float|int|null $prev, float|int|null $lastYear): array
    {
        return [
            'value'    => $value,
            'previous' => $prev,
            'deltaPct' => $kind === 'ratio' ? null : $this->pct($value, $prev),
            'deltaAbs' => $kind === 'ratio' && $value !== null && $prev !== null ? round((float) $value - (float) $prev, 2) : null,
            'lastYear' => $lastYear,
            'yoyPct'   => $kind === 'ratio' ? null : $this->pct($value, $lastYear),
            'yoyAbs'   => $kind === 'ratio' && $value !== null && $lastYear !== null ? round((float) $value - (float) $lastYear, 2) : null,
        ];
    }

    private function pct(float|int|null $cur, float|int|null $prev): ?float
    {
        if ($cur === null || $prev === null || (float) $prev === 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $prev) / (float) $prev * 100, 1);
    }
}
