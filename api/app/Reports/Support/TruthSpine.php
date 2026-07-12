<?php

declare(strict_types=1);

namespace App\Reports\Support;

use App\Models\DailyMetric;

/**
 * The triangulated-truth block (GO-1.4, master plan §4.4 / U1).
 *
 * MER — store revenue ÷ total ad spend — is the SPINE: it is computed from revenue
 * Shopify actually recorded (D-005 basis), so it is the one number here that does not
 * depend on a platform grading its own homework. Beside it sit each platform's OWN
 * reported ROAS, every one carrying its documented bias direction from config/truth.php.
 *
 * Three rules this class exists to enforce:
 *   1. Platform-reported revenue is NEVER summed. Two platforms routinely claim the
 *      same order and neither can see the other; a "total attributed revenue" is a
 *      fiction. They are returned as a LIST, never a total.
 *   2. Every platform figure ships with its annotation. A ROAS without its bias
 *      direction is the thing that destroys trust with senior buyers.
 *   3. Missing ≠ zero. No spend → null ROAS, never 0.0.
 *
 * ROAS is computed in USD (fx snapshots) so the ratio is correct in either currency
 * mode, exactly like every other ratio in the codebase.
 */
class TruthSpine
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /**
     * @param array<int, string> $connected
     * @return array<string, mixed>
     */
    public function forBrand(int $brandId, array $connected, string $start, string $end, bool $usd): array
    {
        $disp = static fn (string $col): string => $usd ? "{$col} * COALESCE(fx_rate_to_usd, 1)" : $col;
        $usdc = static fn (string $col): string => "{$col} * COALESCE(fx_rate_to_usd, 1)";

        // Store truth (D-005: total_sales with refunds added back).
        $revCol = '(COALESCE(total_sales, 0) + COALESCE(refunds_amount, 0))';
        $store  = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'shopify')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("COALESCE(SUM({$disp($revCol)}), 0) AS revenue, COALESCE(SUM({$usdc($revCol)}), 0) AS revenue_usd")
            ->first();

        $storeRevenue    = round((float) ($store->revenue ?? 0), 2);
        $storeRevenueUsd = (float) ($store->revenue_usd ?? 0);

        $platforms     = [];
        $totalSpend    = 0.0;
        $totalSpendUsd = 0.0;

        foreach (self::AD_PLATFORMS as $p) {
            if (! in_array($p, $connected, true)) {
                continue; // not connected → absent, not zero
            }

            $row = DailyMetric::query()
                ->where('brand_id', $brandId)
                ->where('platform', $p)
                ->whereBetween('date', [$start, $end])
                ->selectRaw("
                    COALESCE(SUM({$disp('spend')}), 0)            AS spend,
                    COALESCE(SUM({$usdc('spend')}), 0)            AS spend_usd,
                    COALESCE(SUM({$disp('conversion_value')}), 0) AS value,
                    COALESCE(SUM({$usdc('conversion_value')}), 0) AS value_usd
                ")
                ->first();

            $spend    = round((float) ($row->spend ?? 0), 2);
            $spendUsd = (float) ($row->spend_usd ?? 0);
            $valueUsd = (float) ($row->value_usd ?? 0);

            $totalSpend    += $spend;
            $totalSpendUsd += $spendUsd;

            $platforms[] = [
                'platform'         => $p,
                'spend'            => $spend,
                // What the platform CLAIMS it drove. Displayed, never added to anything.
                'reportedRevenue'  => round((float) ($row->value ?? 0), 2),
                // Missing ≠ zero: no spend → no ratio.
                'reportedRoas'     => $spendUsd > 0.0 ? round($valueUsd / $spendUsd, 2) : null,
                'label'            => (string) config('truth.platform_label'),
                'annotation'       => (string) config('truth.annotations.' . $p, ''),
            ];
        }

        return [
            'storeRevenue' => $storeRevenue,
            'totalSpend'   => round($totalSpend, 2),
            // THE SPINE. USD math so the ratio holds in either currency mode.
            'mer'          => $totalSpendUsd > 0.0 ? round($storeRevenueUsd / $totalSpendUsd, 2) : null,
            'merLabel'     => (string) config('truth.mer.label'),
            'merFormula'   => (string) config('truth.mer.formula'),
            // A LIST — deliberately not a total. See rule 1 above.
            'platforms'    => $platforms,
            'divergenceNote' => (string) config('truth.divergence_note'),
        ];
    }
}
