<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\DailyMetric;
use App\Models\Brand;
use App\Models\EmailDailyMetric;
use App\Models\ShopifyFunnelDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\CustomerMix;
use App\Services\PlatformCredentialService;
use Carbon\CarbonImmutable;

/**
 * REV2 R4 — "NEW S-EX Executive overview... Motion-style stat-tile grid of
 * EVERY decision parameter: Revenue, Ad Spend, Blended ROAS, MER, AOV, Orders,
 * CAC*, New vs Returning %*, Conversion Rate, Sessions, Email revenue*
 * (*where data exists — honest omission otherwise)."
 *
 * M5 end-to-end completion (Kanwar, 2026-07-15 — "complete the report end to
 * end... once we sync all data for 1 brand"): every tile the spec asks for is
 * now WIRED to its real source, so the tile lights up the moment that source
 * has data for the brand — and degrades to an honest `unavailable` reason (or,
 * for email, is omitted entirely) when it doesn't. Nothing is fabricated;
 * missing is never zero (spec rule 9).
 *
 *   - Revenue / Ad Spend / Blended ROAS / AOV / Orders — daily_metrics (D-005
 *     revenue, USD-correct ROAS), same as before.
 *   - MER — store revenue ÷ total ad spend, the TruthSpine SPINE definition
 *     (GO-1.4). Same USD inputs as blendedRoas here, computed inline off the
 *     figures already summed (no second definition; unavailable when no spend).
 *   - Sessions / Conversion Rate — shopify_funnel_daily, the SAME read S9 uses.
 *   - New vs Returning % / CAC — CustomerMix (the shared bounded live ShopifyQL
 *     new/returning count, same source S3 uses). Unavailable when the brand has
 *     no Shopify connection or the token lacks read_reports scope.
 *   - Email revenue — email_daily_metrics, but ONLY surfaced when the brand has
 *     Klaviyo connected (Kanwar: "if klaviyo not connected don't show"); the
 *     tile is omitted from BOTH `tiles` and `unavailable` when it isn't.
 *
 * The 12-month sparkline the spec mentions per tile is the one piece still
 * deferred (a multi-month series query per tile) — logged, not faked.
 */
final class SExSection implements MomSection
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    public function __construct(
        private readonly CustomerMix $customerMix,
        private readonly PlatformCredentialService $credentials,
    ) {
    }

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

        $tiles = [];
        $unavailable = [];

        // Always-local tiles (daily_metrics) — same math this program verified.
        $tiles['revenue']     = $this->tile($cur['revenue'], $cmp['revenue'] ?? null, 'money');
        $tiles['adSpend']     = $this->tile($cur['spend'], $cmp['spend'] ?? null, 'money');
        $tiles['blendedRoas'] = $this->tile($cur['roas'], $cmp['roas'] ?? null, 'ratio');
        $tiles['aov']         = $this->tile($cur['aov'], $cmp['aov'] ?? null, 'money');
        $tiles['orders']      = $this->tile($cur['orders'], $cmp['orders'] ?? null, 'count');

        // MER — store revenue ÷ total ad spend (the spine). Null without spend.
        if ($cur['mer'] !== null) {
            $tiles['mer'] = $this->tile($cur['mer'], $cmp['mer'] ?? null, 'ratio');
        } else {
            $unavailable['mer'] = 'No ad spend recorded this period — MER (revenue ÷ spend) has no denominator yet.';
        }

        // Sessions + conversion rate — shopify_funnel_daily (same read as S9).
        if ($cur['sessions'] !== null) {
            $tiles['sessions']       = $this->tile($cur['sessions'], $cmp['sessions'] ?? null, 'count');
            $tiles['conversionRate'] = $this->tile($cur['conversionRate'], $cmp['conversionRate'] ?? null, 'pct');
        } else {
            $unavailable['sessions']       = 'Run shopify:backfill-funnel for this brand to populate sessions.';
            $unavailable['conversionRate'] = 'Needs shopify_funnel_daily (sessions) — run shopify:backfill-funnel.';
        }

        // New vs Returning % + CAC — shared live ShopifyQL customer counts.
        $mixCur = $this->customerMix->forMonth($brand, $start, $end);
        $mixCmp = $compareWindow !== null ? $this->customerMix->forMonth($brand, $compareWindow[0], $compareWindow[1]) : null;
        if ($mixCur !== null) {
            $tiles['newVsReturningPct'] = $this->tile($mixCur['newPct'], $mixCmp['newPct'] ?? null, 'pct');
            // CAC = total ad spend ÷ NEW customers. Null when there's no spend or
            // no new buyers in the month (missing != a fabricated 0).
            $cac = ($mixCur['new'] > 0 && $cur['spend'] > 0.0) ? round($cur['spend'] / $mixCur['new'], 2) : null;
            $cacCmp = ($mixCmp !== null && $mixCmp['new'] > 0 && (float) ($cmp['spend'] ?? 0) > 0.0)
                ? round((float) $cmp['spend'] / $mixCmp['new'], 2)
                : null;
            if ($cac !== null) {
                $tiles['cac'] = $this->tile($cac, $cacCmp, 'money');
            } else {
                $unavailable['cac'] = 'Needs ad spend and new-customer counts in the same month to compute CAC.';
            }
        } else {
            $unavailable['newVsReturningPct'] = 'Needs the Shopify customer split — connect Shopify with ShopifyQL (read_reports) access.';
            $unavailable['cac']               = 'Needs the Shopify customer split — connect Shopify with ShopifyQL (read_reports) access.';
        }

        // Email revenue — ONLY when Klaviyo is connected (Kanwar: hide otherwise).
        if ($this->hasKlaviyo($brand)) {
            $email = $this->emailRevenue($brand->id, $start, $end);
            $emailCmp = $compareWindow !== null ? $this->emailRevenue($brand->id, $compareWindow[0], $compareWindow[1]) : null;
            if ($email !== null) {
                $tiles['emailRevenue'] = $this->tile($email, $emailCmp, 'money');
            } else {
                $unavailable['emailRevenue'] = 'Klaviyo connected but no attributed revenue synced yet — run klaviyo:backfill.';
            }
        }
        // else: omitted entirely from BOTH tiles and unavailable — a brand
        // without Klaviyo simply has no email channel to show.

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'tiles'  => $tiles,
            // Honest omission, not silence — the SPA greys these out with the
            // reason so it stays visible that a real source is still to arrive.
            'unavailable' => $unavailable,
        ];
    }

    /** @return array{revenue: float, spend: float, roas: ?float, mer: ?float, aov: ?float, orders: int, sessions: ?int, conversionRate: ?float} */
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

        // Sessions & conversion rate — sum shopify_funnel_daily across the
        // 'country' axis (every session lands in some country segment, so the
        // axis reconstructs the brand total — the exact reconciliation S9 uses).
        $funnel = ShopifyFunnelDaily::query()
            ->where('brand_id', $brandId)
            ->where('dimension', 'country')
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(sessions), 0) AS sessions, COALESCE(SUM(completed_checkout), 0) AS purchase')
            ->first();
        $hasFunnel = $funnel !== null && (int) $funnel->n > 0;
        $sessions  = $hasFunnel ? (int) $funnel->sessions : 0;
        $purchase  = $hasFunnel ? (int) $funnel->purchase : 0;

        return [
            'revenue' => round($revenue, 2),
            'spend'   => round($spend, 2),
            'roas'    => $spendUsd > 0.0 ? round($revenueUsd / $spendUsd, 2) : null,
            // MER shares blendedRoas' inputs here (store revenue ÷ total ad
            // spend) — the TruthSpine spine definition; identical by design.
            'mer'     => $spendUsd > 0.0 ? round($revenueUsd / $spendUsd, 2) : null,
            'aov'     => $orders > 0 ? round($revenue / $orders, 2) : null,
            'orders'  => $orders,
            'sessions'       => $hasFunnel ? $sessions : null,
            'conversionRate' => $hasFunnel ? ($sessions > 0 ? round($purchase / $sessions * 100, 2) : 0.0) : null,
        ];
    }

    /** Klaviyo-attributed revenue for the window, or null when there are no rows. */
    private function emailRevenue(int $brandId, string $start, string $end): ?float
    {
        $has = EmailDailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereBetween('date', [$start, $end])
            ->exists();
        if (! $has) {
            return null;
        }

        return round((float) EmailDailyMetric::query()
            ->where('brand_id', $brandId)
            ->whereBetween('date', [$start, $end])
            ->sum('conversion_value'), 2);
    }

    private function hasKlaviyo(Brand $brand): bool
    {
        return $this->credentials->has('klaviyo', 'private_key', (int) $brand->id);
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
