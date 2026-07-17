<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\ShopifyFunnelDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S9 Sessions & CR YoY (slide 21) — daily
 * sessions + conversion rate with prior-year overlay (shopify_funnel_daily)."
 *
 * Totals come from summing `shopify_funnel_daily` dimension='country' across
 * ALL segments for a day — every session lands in some country segment, so
 * summing the axis reconstructs the brand total without a separate
 * un-segmented row (this table has none; same choice for either axis, country
 * was picked arbitrarily since both should reconcile to the same universe).
 */
final class SSessionsCrSection implements MomSection
{
    public function key(): string
    {
        return 'S9';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->activeWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->dailySeries($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'Run shopify:backfill-funnel for this brand to populate sessions & conversion rate.',
            ];
        }

        $compareWindow = $filters->activeComparisonWindow($tz);
        $cmp = $compareWindow !== null ? $this->dailySeries($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $totalSessions = array_sum(array_column($cur, 'sessions'));
        $totalPurchase = array_sum(array_column($cur, 'purchase'));
        $cmpSessions = $cmp !== null ? array_sum(array_column($cmp, 'sessions')) : null;
        $cmpPurchase = $cmp !== null ? array_sum(array_column($cmp, 'purchase')) : null;
        $cmpCvr = ($cmp !== null && $cmpSessions > 0) ? round($cmpPurchase / $cmpSessions * 100, 2) : null;
        $curCvr = $totalSessions > 0 ? round($totalPurchase / $totalSessions * 100, 2) : null;

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'sessions' => ['value' => $totalSessions, 'compare' => $cmpSessions, 'deltaPct' => $this->delta((float) $totalSessions, $cmpSessions !== null ? (float) $cmpSessions : null)],
            'cvr' => ['value' => $curCvr, 'compare' => $cmpCvr, 'deltaPct' => $this->delta($curCvr, $cmpCvr)],
            'dailySessions' => $cur,
            'compareDailySessions' => $cmp,
        ];
    }

    /** @return array<int, array{day: int, sessions: int, purchase: int}>|null */
    private function dailySeries(int $brandId, string $start, string $end): ?array
    {
        $rows = ShopifyFunnelDaily::query()
            ->where('brand_id', $brandId)
            ->where('dimension', 'country')
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->selectRaw('date, COALESCE(SUM(sessions), 0) AS sessions, COALESCE(SUM(completed_checkout), 0) AS purchase')
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(static fn ($r): array => [
            'day'      => (int) CarbonImmutable::parse((string) $r->date)->format('j'),
            'sessions' => (int) $r->sessions,
            'purchase' => (int) $r->purchase,
        ])->values()->all();
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
    }
}
