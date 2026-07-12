<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\DailyMetric;
use Carbon\CarbonImmutable;

/**
 * Monthly pacing (GO-2.1, master plan §5.1): where a brand stands against its target,
 * partway through the month.
 *
 *     expected-by-now = target × (elapsed COMPLETE days ÷ days in month)
 *     status          = actual vs expected-by-now
 *
 * The single most important detail here is "COMPLETE days". If we counted today — a
 * day that has not finished and whose sync has not landed — every brand on the
 * dashboard would read "behind" every morning, purely as an artefact of the clock.
 * That is a wrong number, and a wrong number that cries wolf daily is worse than no
 * number at all. So elapsed days = days with a COMPLETE Shopify row, and actuals sum
 * only those same days. The two always agree by construction.
 *
 * Everything is computed in the BRAND's timezone (guardrail 8). An unset target
 * yields null — never 0, never an invented goal.
 */
class Pacing
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /**
     * @return array<string, mixed>|null null when the brand has no target for the month
     */
    public function forBrand(Brand $brand, ?string $month = null): ?array
    {
        $tz    = $brand->timezone ?: 'UTC';
        $now   = CarbonImmutable::now($tz);
        $month ??= $now->format('Y-m');

        // Resolve the goal: an explicit override for THIS month wins; otherwise the
        // brand's STANDING DEFAULT (month = null), which applies to every un-overridden
        // month. The v1 Settings UI only writes the standing default.
        $target = BrandTarget::query()
            ->where('brand_id', $brand->id)
            ->where('month', $month)
            ->first()
            ?? BrandTarget::query()
                ->where('brand_id', $brand->id)
                ->whereNull('month')
                ->first();

        if ($target === null) {
            return null; // no goal set → nothing to pace against. Never invent one.
        }

        $start       = CarbonImmutable::createFromFormat('Y-m-d', $month . '-01', $tz)->startOfDay();
        $end         = $start->endOfMonth()->startOfDay();
        $daysInMonth = (int) $start->daysInMonth;

        // The window we can honestly measure: month start → the earlier of month end
        // and yesterday (today is partial by definition).
        $yesterday  = $now->subDay()->startOfDay();
        $windowEnd  = $yesterday->lessThan($end) ? $yesterday : $end;
        $isPast     = $windowEnd->equalTo($end);

        if ($windowEnd->lessThan($start)) {
            // The month hasn't started yet in this brand's timezone.
            return $this->payload($brand, $month, $target, 0, $daysInMonth, 0.0, 0.0, null, true);
        }

        // Elapsed = days with a COMPLETE Shopify row. Not "days on the calendar".
        $completeDays = (int) DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->whereBetween('date', [$start->toDateString(), $windowEnd->toDateString()])
            ->distinct()
            ->count('date');

        // Actuals over exactly those complete days (D-005 revenue basis).
        $revenue = (float) DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->whereBetween('date', [$start->toDateString(), $windowEnd->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)), 0) AS v')
            ->value('v');

        // The ROAS ratio must be computed in USD (fx snapshots), NOT native ÷ native —
        // otherwise a brand whose revenue and spend are booked in different currencies
        // gets a ratio that is silently wrong. Same math as the dashboard engine and the
        // truth spine (GO-1.4); we do not invent a second definition of ROAS.
        $revenueUsd = (float) DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', 'shopify')
            ->where('is_complete', true)
            ->whereBetween('date', [$start->toDateString(), $windowEnd->toDateString()])
            ->selectRaw('COALESCE(SUM((COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0)) * COALESCE(fx_rate_to_usd, 1)), 0) AS v')
            ->value('v');

        $spendRow = DailyMetric::query()
            ->where('brand_id', $brand->id)
            ->whereIn('platform', self::AD_PLATFORMS)
            ->whereBetween('date', [$start->toDateString(), $windowEnd->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(spend, 0)), 0) AS native,
                         COALESCE(SUM(COALESCE(spend, 0) * COALESCE(fx_rate_to_usd, 1)), 0) AS usd')
            ->first();

        $spend    = (float) ($spendRow->native ?? 0);
        $spendUsd = (float) ($spendRow->usd ?? 0);

        return $this->payload(
            $brand, $month, $target, $completeDays, $daysInMonth,
            $revenue, $spend, $windowEnd->toDateString(), $isPast,
            // USD-correct ratio; null (never 0) when there is no spend to divide by.
            $spendUsd > 0.0 ? $revenueUsd / $spendUsd : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Brand $brand,
        string $month,
        BrandTarget $target,
        int $completeDays,
        int $daysInMonth,
        float $revenue,
        float $spend,
        ?string $through,
        bool $monthEnded,
        ?float $roasUsd = null,
    ): array {
        // Fraction of the month we can actually SEE. Zero complete days → we know
        // nothing yet, so expected-by-now is 0 and no status is claimed.
        $elapsed = $daysInMonth > 0 ? $completeDays / $daysInMonth : 0.0;

        // What the brand must average, per remaining day, to still hit the goal. Null
        // when there is no revenue target, or when the month is already over.
        $remainingDays  = max(0, $daysInMonth - $completeDays);
        $neededPerDay   = ($target->revenue_target !== null && $remainingDays > 0)
            ? round(max(0.0, (float) $target->revenue_target - $revenue) / $remainingDays, 2)
            : null;

        return [
            'month'          => $month,
            // A standing default applies to every month with no explicit override.
            'isStandingDefault' => $target->month === null,
            'currency'       => $brand->base_currency ?: 'USD',
            'daysInMonth'    => $daysInMonth,
            'completeDays'   => $completeDays,
            'remainingDays'  => $remainingDays,
            'neededPerDay'   => $neededPerDay,
            'elapsedPct'     => round($elapsed * 100, 1),
            'dataThrough'    => $through,
            'monthEnded'     => $monthEnded,
            // Each metric paces independently; an unset target yields null, never 0.
            'revenue' => $this->metric((float) $revenue, $target->revenue_target, $elapsed, 'higher'),
            'spend'   => $this->metric((float) $spend, $target->spend_cap, $elapsed, 'lower'),
            'targets' => [
                'revenue' => $target->revenue_target,
                'spendCap' => $target->spend_cap,
                'roas'    => $target->roas_target,
                'mer'     => $target->mer_target,
            ],
            // Ratio targets don't pace against elapsed time — a 3× ROAS target is 3× on
            // day 2 and 3× on day 30. They are compared to the window's actual ratio,
            // computed in USD (fx snapshots) so it is correct across currencies.
            'roas' => $this->ratio($roasUsd, $target->roas_target ?? $target->mer_target),
        ];
    }

    /**
     * @param float|null $target null = not set → the whole block is null (never 0)
     * @param string $direction 'higher' = beating the target is good (revenue);
     *                          'lower'  = staying under it is good (spend cap)
     * @return array<string, mixed>|null
     */
    private function metric(float $actual, ?float $target, float $elapsed, string $direction): ?array
    {
        if ($target === null) {
            return null;
        }

        $expected = round($target * $elapsed, 2);
        $delta    = round($actual - $expected, 2);   // + = above the pace line

        // With no complete days we have measured nothing — claim no status rather than
        // declaring a brand "behind" on the strength of zero data.
        $status = $elapsed <= 0.0
            ? 'unknown'
            : ($direction === 'higher'
                ? ($delta >= 0 ? 'on_pace' : 'behind')
                : ($delta <= 0 ? 'on_pace' : 'over'));

        return [
            'actual'      => round($actual, 2),
            'target'      => round($target, 2),
            'expectedNow' => $expected,
            'delta'       => $delta,
            'pctOfTarget' => $target > 0.0 ? round($actual / $target * 100, 1) : null,
            'status'      => $status,   // on_pace | behind | over | unknown
        ];
    }

    /** @return array<string, mixed>|null */
    private function ratio(?float $actual, ?float $target): ?array
    {
        if ($target === null) {
            return null;
        }

        return [
            'actual' => $actual !== null ? round($actual, 2) : null,   // null = no spend yet, never 0
            'target' => round($target, 2),
            'status' => $actual === null ? 'unknown' : ($actual >= $target ? 'on_pace' : 'behind'),
        ];
    }
}
