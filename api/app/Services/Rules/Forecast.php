<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\Brand;
use App\Models\DailyMetric;
use Carbon\CarbonImmutable;

/**
 * Revenue forecast baseline (GO-2.3, master plan §5.3) — seasonal-naive + drift,
 * per fpp3 §5.2. Zero new dependencies: SQL and arithmetic.
 *
 *     forecast(d) = revenue(d − 1 year) × trend
 *     trend       = (trailing 28d this year) ÷ (same 28d one year ago)
 *
 * Both terms are returned separately so the number can always be taken apart:
 * `seasonal` is what the brand actually did on that date last year, `trend` is the
 * single multiplier applied to it. Nothing is hidden inside a black box.
 *
 * THE REFUSAL IS THE FEATURE. This engine returns status='insufficient_history' —
 * and no numbers at all — when the brand is too new or last year doesn't cover the
 * window. A forecast built on absent history isn't conservative, it's invented, and
 * one invented number in a client plan costs more trust than the forecast ever earns.
 *
 * Every payload carries the `Modeled — baseline forecast` label (§0 law 1). A forecast
 * rendered without it is a bug.
 */
class Forecast
{
    /**
     * @return array<string, mixed> status: 'ok' | 'insufficient_history'
     */
    public function forBrand(Brand $brand, ?int $horizonDays = null): array
    {
        $tz      = $brand->timezone ?: 'UTC';
        $horizon = max(1, $horizonDays ?? (int) config('forecast.horizon_days', 90));
        $label   = (string) config('forecast.label');

        $today     = CarbonImmutable::now($tz)->startOfDay();
        $yesterday = $today->subDay();

        // --- Gate 1: does this brand have enough history to say anything at all? ----
        $minHistory = (int) config('forecast.min_history_days', 90);
        $historyDays = (int) DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->distinct()
            ->count('date');

        if ($historyDays < $minHistory) {
            return $this->refuse(
                $label,
                "This brand has {$historyDays} complete days of revenue history; a baseline needs at least {$minHistory}.",
                ['historyDays' => $historyDays, 'requiredDays' => $minHistory],
            );
        }

        // --- Gate 2: does LAST YEAR actually cover the window we're forecasting? ----
        // The seasonal term IS last year. Without it there is nothing to project from.
        $windowStart = $today;
        $windowEnd   = $today->addDays($horizon - 1);
        $lyStart     = $windowStart->subYear();
        $lyEnd       = $windowEnd->subYear();

        /** @var array<string, float> $lastYear date => revenue */
        $lastYear = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->whereBetween('date', [$lyStart->toDateString(), $lyEnd->toDateString()])
            ->selectRaw('date, COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0) AS revenue')
            ->get()
            ->mapWithKeys(fn ($r): array => [
                CarbonImmutable::parse((string) $r->date)->toDateString() => (float) $r->revenue,
            ])
            ->all();

        $coveredDays = count($lastYear);
        $coveragePct = $horizon > 0 ? (int) round($coveredDays / $horizon * 100) : 0;
        $minCoverage = (int) config('forecast.min_lastyear_coverage_pct', 70);

        if ($coveragePct < $minCoverage) {
            return $this->refuse(
                $label,
                "Last year covers only {$coveragePct}% of the next {$horizon} days ({$coveredDays} of {$horizon}); "
                . "a seasonal baseline needs at least {$minCoverage}%. Backfill more history and this lights up.",
                ['coveragePct' => $coveragePct, 'requiredCoveragePct' => $minCoverage, 'historyDays' => $historyDays],
            );
        }

        // --- The trend (drift) term -------------------------------------------------
        $trendWindow = (int) config('forecast.trend_window_days', 28);
        $curFrom     = $yesterday->subDays($trendWindow - 1);
        $curTotals   = $this->windowTotals($brand->id, $curFrom, $yesterday);
        $lyTotals    = $this->windowTotals($brand->id, $curFrom->subYear(), $yesterday->subYear());

        $minComplete = (int) config('forecast.trend_min_complete_days', 21);
        $clamp       = (array) config('forecast.trend_clamp', ['min' => 0.5, 'max' => 2.0]);

        $trend        = 1.0;
        $trendApplied = false;
        $trendClamped = false;
        $trendNote    = 'No trend term: not enough complete days in one of the 28-day windows, so the forecast is pure seasonal-naive.';

        if ($curTotals['days'] >= $minComplete && $lyTotals['days'] >= $minComplete && $lyTotals['revenue'] > 0.0) {
            $raw   = $curTotals['revenue'] / $lyTotals['revenue'];
            $trend = max((float) $clamp['min'], min((float) $clamp['max'], $raw));
            $trendClamped = abs($trend - $raw) > 0.0001;
            $trendApplied = true;
            $trendNote = $trendClamped
                ? 'Trend was ' . round($raw, 2) . '× and has been clamped to ' . round($trend, 2)
                    . '× — a multiplier that extreme is almost always an artefact of a near-zero base, not real momentum.'
                : 'Trend: the last ' . $trendWindow . ' days ran ' . round($trend, 2) . '× the same period a year ago.';
        }

        // --- Project each day -------------------------------------------------------
        $days  = [];
        $total = 0.0;
        $seasonalTotal = 0.0;

        for ($i = 0; $i < $horizon; $i++) {
            $d   = $windowStart->addDays($i);
            $ly  = $d->subYear()->toDateString();
            $seasonal = $lastYear[$ly] ?? null;

            if ($seasonal === null) {
                // A gap in last year is a gap — not a zero. It contributes nothing and
                // is reported as missing, so the total is honest about what it omits.
                $days[] = ['date' => $d->toDateString(), 'seasonal' => null, 'forecast' => null];
                continue;
            }

            $f = round($seasonal * $trend, 2);
            $days[] = ['date' => $d->toDateString(), 'seasonal' => round($seasonal, 2), 'forecast' => $f];
            $total += $f;
            $seasonalTotal += $seasonal;
        }

        return [
            'status'       => 'ok',
            'label'        => $label,                       // MUST render with every number
            'methodNote'   => (string) config('forecast.method_note'),
            'currency'     => $brand->base_currency ?: 'USD',
            'horizonDays'  => $horizon,
            'periodStart'  => $windowStart->toDateString(),
            'periodEnd'    => $windowEnd->toDateString(),
            'trend'        => round($trend, 3),
            'trendApplied' => $trendApplied,
            'trendClamped' => $trendClamped,
            'trendNote'    => $trendNote,
            'coverage'     => [
                'lastYearDays' => $coveredDays,
                'ofDays'       => $horizon,
                'pct'          => $coveragePct,
                // Days last year had no row → they contribute nothing, never a zero.
                'missingDays'  => $horizon - $coveredDays,
            ],
            'days'         => $days,
            'totals'       => [
                'forecast'     => round($total, 2),
                'seasonalOnly' => round($seasonalTotal, 2),   // the un-trended baseline, shown for comparison
            ],
        ];
    }

    /**
     * Month-end projection for the CURRENT month: actual complete-day revenue so far
     * plus the forecast for the days remaining. Feeds pacing (GO-2.1) and GO-4 sizing.
     *
     * @return array<string, mixed>|null null when the forecast refused
     */
    public function monthEndProjection(Brand $brand): ?array
    {
        $tz    = $brand->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $end   = $today->endOfMonth()->startOfDay();

        $remaining = (int) $today->diffInDays($end) + 1; // today .. month end inclusive
        $fc = $this->forBrand($brand, max(1, $remaining));

        if ($fc['status'] !== 'ok') {
            return null;
        }

        $actual = (float) DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->whereBetween('date', [$today->startOfMonth()->toDateString(), $today->subDay()->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)), 0) AS v')
            ->value('v');

        return [
            'label'          => $fc['label'],
            'actualToDate'   => round($actual, 2),
            'forecastRest'   => $fc['totals']['forecast'],
            'projectedMonth' => round($actual + $fc['totals']['forecast'], 2),
            'currency'       => $fc['currency'],
        ];
    }

    /** @return array{revenue: float, days: int} */
    private function windowTotals(int $brandId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)), 0) AS revenue, COUNT(DISTINCT date) AS days')
            ->first();

        return ['revenue' => (float) ($row->revenue ?? 0), 'days' => (int) ($row->days ?? 0)];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function refuse(string $label, string $reason, array $meta): array
    {
        // Deliberately NO numbers. Refusing loudly beats extrapolating quietly.
        return [
            'status' => 'insufficient_history',
            'label'  => $label,
            'reason' => $reason,
        ] + $meta;
    }
}
