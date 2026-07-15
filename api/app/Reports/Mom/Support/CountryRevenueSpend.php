<?php

declare(strict_types=1);

namespace App\Reports\Mom\Support;

use App\Models\CommerceDailyMetric;
use App\Models\MetaBreakdownDaily;
use App\Support\CountryCodes;

/**
 * M2 (monthly-report-v2-mom.md §M2 — S4/S5/S6): joins Shopify commerce revenue
 * BY COUNTRY (`commerce_daily_metrics`, dimension_type='country', keyed on a
 * country NAME like "Spain") against Meta ad spend BY COUNTRY
 * (`meta_breakdown_daily`, breakdown_type='country', keyed on an ISO-2 code
 * like "ES") — the exact name-vs-code mismatch `App\Support\CountryCodes`'s
 * own docblock exists to solve ("Without normalisation the monthly report's
 * ROAS-by-country join... silently miss on every row"). Both S5 (country
 * revenue) and S6 (ROAS by country) need this same join; S4 (revenue by TIER)
 * additionally folds it through `CountryTiers::resolve()`. Shared here once so
 * the three sections can never quietly drift out of sync with each other.
 *
 * A commerce country name that doesn't resolve to an ISO-2 code is kept under
 * the '__unmatched' key with its raw name as the label — the revenue is never
 * dropped, just flagged as unable to join to ad spend or a tier.
 */
final class CountryRevenueSpend
{
    /**
     * @return array<string, array{iso2: string, label: string, revenue: float, orders: int, spend: float}>
     */
    public function compute(int $brandId, string $start, string $end): array
    {
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))'; // D-005

        $commerceRows = CommerceDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('dimension_type', 'country')
            ->whereBetween('date', [$start, $end])
            ->groupBy('dimension_key')
            ->selectRaw("dimension_key, MAX(dimension_label) AS label,
                COALESCE(SUM({$revCol}), 0) AS revenue,
                COALESCE(SUM(orders), 0) AS orders")
            ->get();

        $spendRows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->where('breakdown_type', 'country')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key')
            ->selectRaw('segment_key, COALESCE(SUM(spend), 0) AS spend')
            ->get();

        $out = [];
        foreach ($commerceRows as $r) {
            $name = (string) $r->dimension_key;
            $iso2 = CountryCodes::toIso2($name);
            $key  = $iso2 ?? '__unmatched_' . $name;
            $out[$key] = [
                'iso2'    => $iso2 ?? '',
                'label'   => (string) ($r->label ?: $name),
                'revenue' => round((float) $r->revenue, 2),
                'orders'  => (int) $r->orders,
                'spend'   => 0.0,
            ];
        }

        foreach ($spendRows as $r) {
            $iso2 = CountryCodes::toIso2((string) $r->segment_key);
            if ($iso2 === null) {
                continue; // an unresolvable Meta country code has nothing to join revenue to
            }
            if (isset($out[$iso2])) {
                $out[$iso2]['spend'] = round((float) $r->spend, 2);
            } else {
                // Spend with no matching commerce row this window (e.g. prospecting
                // into a market with no orders yet) — still real, still shown.
                $out[$iso2] = ['iso2' => $iso2, 'label' => $iso2, 'revenue' => 0.0, 'orders' => 0, 'spend' => round((float) $r->spend, 2)];
            }
        }

        return $out;
    }
}
