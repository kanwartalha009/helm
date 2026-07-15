<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S2 Total sales evolution. Daily revenue
 * line for the report month with prior-year same-month overlay (both from
 * daily_metrics; plain SVG/div chart per report conventions)."
 *
 * Uses the report-wide comparison filter (REV2 R3) for the overlay window
 * rather than hardcoding "prior year" — consistent with every other section
 * in this program; a client can still pick 'Same month last year' as the
 * report's compare mode to get the PDF's exact traditional view.
 */
final class SSalesEvolutionSection implements MomSection
{
    public function key(): string
    {
        return 'S2';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->dailyRevenue($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Shopify daily data synced for this brand/month yet.',
            ];
        }

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $this->dailyRevenue($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'series'  => $cur,
            'compareSeries' => $cmp,
            'total'   => round(array_sum(array_column($cur, 'revenue')), 2),
            'compareTotal' => $cmp !== null ? round(array_sum(array_column($cmp, 'revenue')), 2) : null,
        ];
    }

    /** @return array<int, array{day: int, revenue: float}>|null */
    private function dailyRevenue(int $brandId, string $start, string $end): ?array
    {
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005

        $rows = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->groupBy('date')
            ->selectRaw("date, COALESCE(SUM({$revCol}), 0) AS revenue")
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(static fn ($r): array => [
            'day'     => (int) CarbonImmutable::parse((string) $r->date)->format('j'),
            'revenue' => round((float) $r->revenue, 2),
        ])->values()->all();
    }
}
