<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\DailyMetric;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M2 (monthly-report-v2-mom.md §M2) — "S12 Prior-year next-month lookback
 * (slide 27) — next month's daily sales LAST year ('what spiked this time
 * last year') — pure daily_metrics."
 *
 * A FIXED lookback, not the report-wide comparison filter (REV2 R3) — the
 * spec defines this one specific window (report month + 1, minus 1 year) so
 * the agency can preview what historically spiked right after this point last
 * year. `compare`/`compare_month` on the request do not affect this section.
 */
final class SPriorYearLookbackSection implements MomSection
{
    public function key(): string
    {
        return 'S12';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }

        $lookbackStart = CarbonImmutable::parse($window[0], $tz)->addMonth()->subYear()->startOfMonth();
        $lookbackEnd   = $lookbackStart->endOfMonth();

        $rows = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$lookbackStart->toDateString(), $lookbackEnd->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, COALESCE(SUM((COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))), 0) AS revenue')
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => "No Shopify data on file for {$lookbackStart->isoFormat('MMMM YYYY')} — the lookback month a year before next month.",
            ];
        }

        $series = $rows->map(static fn ($r): array => [
            'day'     => (int) CarbonImmutable::parse((string) $r->date)->format('j'),
            'revenue' => round((float) $r->revenue, 2),
        ])->values()->all();

        $peak = collect($series)->sortByDesc('revenue')->first();

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'lookbackMonth' => $lookbackStart->format('Y-m'),
            'lookbackLabel' => $lookbackStart->isoFormat('MMMM YYYY'),
            'series' => $series,
            'total'  => round((float) collect($series)->sum('revenue'), 2),
            'peakDay' => $peak,
        ];
    }
}
