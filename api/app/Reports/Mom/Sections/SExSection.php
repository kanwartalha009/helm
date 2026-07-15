<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\DailyMetric;
use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * REV2 R4 — "NEW S-EX Executive overview... Motion-style stat-tile grid of
 * EVERY decision parameter: Revenue, Ad Spend, Blended ROAS, MER, AOV, Orders,
 * CAC*, New vs Returning %*, Conversion Rate, Sessions, Email revenue*
 * (*where data exists — honest omission otherwise). Each tile: value, compare
 * delta (arrow + %), 12-month sparkline."
 *
 * THIS PASS builds the 5 tiles computable straight from daily_metrics with the
 * same math this program already verified (D-005 revenue, USD-correct ROAS):
 * Revenue, Ad Spend, Blended ROAS, AOV, Orders — plus each tile's compare delta
 * against `filters->compareMonthWindow()` (REV2 R3).
 *
 * NOT wired yet, each requiring a real read of a system this pass didn't open:
 * MER (needs the GO-1.4 TruthSpine service — a bias-annotated blend, not a
 * plain ratio, and getting that wrong would be worse than omitting it), CAC +
 * New vs Returning % (need the ShopifyQL customer_type probe — M2's own gate,
 * not yet run this session), Conversion Rate + Sessions (shopify_funnel_daily —
 * unread this pass), Email revenue (email_daily_metrics — unread this pass),
 * and the 12-month sparkline (needs a real multi-month series query, deferred
 * with the rest). Each renders as `available: false` with a `reason`, per
 * spec rule 9 (missing != zero) — never a fabricated number.
 */
final class SExSection implements MomSection
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    public function key(): string
    {
        return 'S-EX';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $compareWindow = $filters->compareMonthWindow($tz);
        $cur = $this->metrics($brand->id, $start, $end);
        $cmp = $compareWindow !== null ? $this->metrics($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $tiles = [
            'revenue'    => $this->tile($cur['revenue'], $cmp['revenue'] ?? null, 'money'),
            'adSpend'    => $this->tile($cur['spend'], $cmp['spend'] ?? null, 'money'),
            'blendedRoas' => $this->tile($cur['roas'], $cmp['roas'] ?? null, 'ratio'),
            'aov'        => $this->tile($cur['aov'], $cmp['aov'] ?? null, 'money'),
            'orders'     => $this->tile($cur['orders'], $cmp['orders'] ?? null, 'count'),
        ];

        $unavailable = [
            'mer'                 => 'Needs the TruthSpine MER blend — not wired in this pass.',
            'cac'                 => 'Needs the ShopifyQL customer_type probe — not run in this pass (M2 gate).',
            'newVsReturningPct'   => 'Needs the ShopifyQL customer_type probe — not run in this pass (M2 gate).',
            'conversionRate'      => 'Needs shopify_funnel_daily — not wired in this pass.',
            'sessions'            => 'Needs shopify_funnel_daily — not wired in this pass.',
            'emailRevenue'        => 'Needs email_daily_metrics — not wired in this pass.',
        ];

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'tiles'  => $tiles,
            // Every tile the spec asks for that this pass didn't build — honest
            // omission, not silence. The SPA renders these greyed out with the
            // reason, not hidden entirely, so it's visible work remains.
            'unavailable' => $unavailable,
        ];
    }

    /** @return array{revenue: float, spend: float, roas: ?float, aov: ?float, orders: int} */
    private function metrics(int $brandId, string $start, string $end): array
    {
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005

        $c = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("COALESCE(SUM({$revCol}), 0) AS revenue, COALESCE(SUM({$revCol} * COALESCE(fx_rate_to_usd, 1)), 0) AS revenue_usd, COALESCE(SUM(orders), 0) AS orders")
            ->first();

        $revenue    = (float) ($c->revenue ?? 0);
        $revenueUsd = (float) ($c->revenue_usd ?? 0);
        $orders     = (int) ($c->orders ?? 0);

        $spend = 0.0;
        $spendUsd = 0.0;
        foreach (self::AD_PLATFORMS as $p) {
            $s = DailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('platform', $p)
                ->whereBetween('date', [$start, $end])
                ->selectRaw('COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS spend_usd')
                ->first();
            $spend    += (float) ($s->spend ?? 0);
            $spendUsd += (float) ($s->spend_usd ?? 0);
        }

        return [
            'revenue' => round($revenue, 2),
            'spend'   => round($spend, 2),
            'roas'    => $spendUsd > 0.0 ? round($revenueUsd / $spendUsd, 2) : null,
            'aov'     => $orders > 0 ? round($revenue / $orders, 2) : null,
            'orders'  => $orders,
        ];
    }

    /** @return array{value: mixed, compare: mixed, deltaPct: ?float, format: string} */
    private function tile(mixed $value, mixed $compareValue, string $format): array
    {
        $deltaPct = null;
        if ($value !== null && $compareValue !== null && (float) $compareValue !== 0.0) {
            $deltaPct = round(((float) $value - (float) $compareValue) / (float) $compareValue * 100, 1);
        }

        return [
            'value'    => $value,
            'compare'  => $compareValue,
            'deltaPct' => $deltaPct,
            'format'   => $format,
        ];
    }
}
