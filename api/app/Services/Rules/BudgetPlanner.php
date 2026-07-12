<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\Brand;
use App\Models\BudgetPlan;
use App\Models\DailyMetric;
use Carbon\CarbonImmutable;

/**
 * Budget planner (GO-2.2, master plan §5.2) — a PLAN DOCUMENT, never a control surface.
 *
 * For each ad platform the brand runs, it shows what actually happened over the
 * trailing 90 days, the monthly run-rate that implies, what the operator PLANS to
 * spend next month, and the delta between the two. That is all. Helm does not write
 * budgets to Meta, Google or TikTok — ever (doctrine §2: platform automation absorbs
 * execution; Helm's lane is verification, strategy and creative supply).
 *
 * Honesty rules:
 *  - Run-rate is derived from days that actually have rows, not from a calendar
 *    assumption. A platform with 12 days of data does not get its spend divided by 90.
 *  - ROAS is platform-REPORTED (the platform's own conversion_value) and is labelled as
 *    such — it is not store truth. MER is the spine (GO-1.4); this grid is about
 *    allocating spend, not about proving revenue.
 *  - No history → null, never 0. A platform with no spend is "no data", not "€0 planned".
 */
class BudgetPlanner
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];
    private const LOOKBACK_DAYS = 90;

    /**
     * @return array<string, mixed>
     */
    public function forBrand(Brand $brand, string $month): array
    {
        $tz        = $brand->timezone ?: 'UTC';
        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $from      = $yesterday->subDays(self::LOOKBACK_DAYS - 1);

        $target      = CarbonImmutable::createFromFormat('Y-m-d', $month . '-01', $tz)->startOfDay();
        $daysInMonth = (int) $target->daysInMonth;

        $connected = $brand->connections()->where('status', 'active')->pluck('platform')->unique()->all();

        $plans = BudgetPlan::query()
            ->where('brand_id', $brand->id)
            ->where('month', $month)
            ->get()
            ->keyBy(fn (BudgetPlan $p): string => $p->platform . '|' . $p->country);

        $rows           = [];
        $totalRunRate   = 0.0;
        $totalPlanned   = 0.0;
        $anyPlanned     = false;

        foreach (self::AD_PLATFORMS as $platform) {
            if (! in_array($platform, $connected, true)) {
                continue; // not connected → absent, not a zero row
            }

            $agg = DailyMetric::query()
                ->where('brand_id', $brand->id)
                ->where('platform', $platform)
                ->whereBetween('date', [$from->toDateString(), $yesterday->toDateString()])
                ->selectRaw('
                    COALESCE(SUM(COALESCE(spend, 0)), 0)                                   AS spend,
                    COALESCE(SUM(COALESCE(spend, 0) * COALESCE(fx_rate_to_usd, 1)), 0)     AS spend_usd,
                    COALESCE(SUM(COALESCE(conversion_value, 0) * COALESCE(fx_rate_to_usd, 1)), 0) AS value_usd,
                    COUNT(DISTINCT date)                                                   AS days
                ')
                ->first();

            $spend    = round((float) ($agg->spend ?? 0), 2);
            $spendUsd = (float) ($agg->spend_usd ?? 0);
            $valueUsd = (float) ($agg->value_usd ?? 0);
            $days     = (int) ($agg->days ?? 0);

            // Run-rate from days we actually HAVE. Dividing 12 days of spend by 90 would
            // understate the run-rate by 7×, and the plan built on it would be wrong.
            $perDay  = $days > 0 ? $spend / $days : null;
            $runRate = $perDay !== null ? round($perDay * $daysInMonth, 2) : null;

            $plan    = $plans[$platform . '|'] ?? null;
            $planned = $plan !== null ? round((float) $plan->planned_spend, 2) : null;

            if ($runRate !== null) {
                $totalRunRate += $runRate;
            }
            if ($planned !== null) {
                $totalPlanned += $planned;
                $anyPlanned = true;
            }

            $rows[] = [
                'platform'      => $platform,
                'country'       => '',                                   // v1 plans at platform level
                'spend90'       => $days > 0 ? $spend : null,            // missing ≠ 0
                'days90'        => $days,
                'reportedRoas'  => $spendUsd > 0.0 ? round($valueUsd / $spendUsd, 2) : null,
                'runRateMonth'  => $runRate,
                'plannedSpend'  => $planned,
                'note'          => $plan?->note,
                // + = planning to spend MORE than the current run-rate.
                'delta'         => ($planned !== null && $runRate !== null) ? round($planned - $runRate, 2) : null,
                'deltaPct'      => ($planned !== null && $runRate !== null && $runRate > 0.0)
                    ? round(($planned - $runRate) / $runRate * 100, 1)
                    : null,
            ];
        }

        return [
            'month'        => $month,
            'currency'     => $brand->base_currency ?: 'USD',
            'lookbackDays' => self::LOOKBACK_DAYS,
            'daysInMonth'  => $daysInMonth,
            'rows'         => $rows,
            'totals'       => [
                'runRateMonth' => $rows === [] ? null : round($totalRunRate, 2),
                'plannedSpend' => $anyPlanned ? round($totalPlanned, 2) : null,
                'delta'        => $anyPlanned ? round($totalPlanned - $totalRunRate, 2) : null,
            ],
            // Said out loud on every render — this grid changes nothing anywhere.
            'executionNote' => 'This is a plan document. Helm does not push budgets to Meta, Google or TikTok — '
                . 'set the numbers in each ad platform yourself. ROAS here is platform-reported (each platform '
                . 'grading its own work), not store truth; MER on the brand page is the honest figure.',
        ];
    }
}
