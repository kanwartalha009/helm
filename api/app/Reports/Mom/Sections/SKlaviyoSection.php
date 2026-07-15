<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Models\EmailDailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M3 (monthly-report-v2-mom.md §M3) — "S18 Klaviyo attribution + list growth
 * (slides 24-25). DEPENDS ON GO-1 Klaviyo adapter (master plan). Until then:
 * section renders a 'Connect Klaviyo' placeholder internally and auto-hides
 * on shares. Build the section shell now, data wiring lands with GO-1."
 *
 * CORRECTION to the spec's own premise (clarity-first step 2/3 — current
 * reality over the numbered spec): GO-1 is NOT pending. The tracker's own
 * change-log shows "GO-1 exit: ☑ COMPLETE (2026-07-12)" — before this
 * session started. `email_daily_metrics` (GO-1.1) is live, brand-scoped
 * Klaviyo keys + `klaviyo:backfill` exist, and v1's `MonthlyReport::
 * emailSection()` already reads real attributed revenue from it. So this
 * section does NOT need to be a placeholder shell — it reads REAL Klaviyo
 * revenue attribution the same way v1 does (independently reimplemented,
 * REV2 R7 — no v1 files touched), with the same non-negotiable honesty law:
 * Klaviyo revenue is its OWN channel, last-touch within Klaviyo's windows,
 * NEVER summed into store or ad revenue — `honestyBox` renders with it always.
 *
 * "List growth" (subscriber/list-size trend) is the one half of S18 that
 * genuinely IS unbuilt: no subscriber-count sync exists anywhere in this
 * codebase (only attributed-revenue rows) — logged unavailable, not faked.
 *
 * A brand with no Klaviyo key configured still gets the honest
 * 'needs_source' "Connect Klaviyo" state the spec asked for — that part of
 * the spec's intent is preserved, just not as this section's ONLY state.
 */
final class SKlaviyoSection implements MomSection
{
    public function key(): string
    {
        return 'S18';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->metrics($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'Connect Klaviyo — add this brand’s private key in Settings and run klaviyo:backfill to populate email revenue.',
            ];
        }

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $this->metrics($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $benchmark = (float) config('momreport.benchmarks.klaviyo_revenue_pct_benchmark', 50.0);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'revenue' => $cur['revenue'],
            'orders'  => $cur['orders'],
            'shareOfStore' => [
                'value'    => $cur['shareOfStore'],
                'compare'  => $cmp['shareOfStore'] ?? null,
                'deltaPct' => $this->delta($cur['shareOfStore'], $cmp['shareOfStore'] ?? null),
            ],
            'benchmark' => $benchmark,
            'splits' => $cur['splits'],
            'rows'   => $cur['rows'],
            'label'      => 'Verified — Klaviyo-attributed',
            'honestyBox' => (string) config('klaviyo.honesty_box'),
            'unavailable' => [
                'listGrowth' => 'No subscriber/list-size sync exists in this codebase yet — only attributed-revenue rows.',
            ],
        ];
    }

    /** @return array{revenue: float, orders: int, shareOfStore: ?float, splits: array, rows: array}|null */
    private function metrics(int $brandId, string $start, string $end): ?array
    {
        $rows = EmailDailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereBetween('date', [$start, $end])
            ->groupBy('source', 'source_id')
            ->selectRaw('source, source_id, MAX(source_name) AS name,
                COALESCE(SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)), 0) AS revenue_usd,
                COALESCE(SUM(conversion_value), 0) AS revenue,
                COALESCE(SUM(conversions), 0) AS orders')
            ->orderByDesc('revenue')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $revenue = round((float) $rows->sum('revenue'), 2);
        $orders  = (int) $rows->sum('orders');

        $storeRev = (float) DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COALESCE(SUM((COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))), 0) AS v')
            ->value('v');

        $flows     = $rows->where('source', 'flow');
        $campaigns = $rows->where('source', 'campaign');

        return [
            'revenue' => $revenue,
            'orders'  => $orders,
            'shareOfStore' => $storeRev > 0.0 ? round($revenue / $storeRev * 100, 1) : null,
            'splits' => [
                'flow'     => ['revenue' => round((float) $flows->sum('revenue'), 2), 'orders' => (int) $flows->sum('orders')],
                'campaign' => ['revenue' => round((float) $campaigns->sum('revenue'), 2), 'orders' => (int) $campaigns->sum('orders')],
            ],
            'rows' => $rows->take(10)->map(static fn ($r): array => [
                'source'  => (string) $r->source,
                'id'      => (string) $r->source_id,
                'name'    => $r->name !== null && $r->name !== '' ? (string) $r->name : null,
                'revenue' => round((float) $r->revenue, 2),
                'orders'  => (int) $r->orders,
            ])->values()->all(),
        ];
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
    }
}
