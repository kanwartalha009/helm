<?php

declare(strict_types=1);

namespace App\Services\Recon;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;

/**
 * Ads-spend reconciliation self-check (Kanwar, 2026-07-22 — Bruna amboise
 * incident). "Helm should have caught this before the client did." This makes
 * that structural: for a brand over a window, it sums every ads roll-up table and
 * asserts the invariant that each MUST reconcile to its source of truth, per
 * brand-day. Any drift is quantified in € and %, so a dropping/attribution bug
 * (H1/H2 class) surfaces as an amber/red alert with the exact per-day diff —
 * numbers, not adjectives.
 *
 * Granularity: ad_product_daily and daily_metrics are both keyed per brand-day
 * (no ad-account column exists on either), so the reconcile is brand-day — the
 * finest level both sides share. Everything is compared in the stored native
 * currency; a brand's ad accounts share a currency in practice, and mixing would
 * itself show as drift (which is the point).
 *
 * The five invariant pairs (actual  vs  reference-of-truth):
 *   1. ad_product_daily (meta)      vs daily_metrics (meta)              — the incident pair
 *   2. ad_campaign_daily (meta)     vs daily_metrics (meta)
 *      ad_campaign_daily (google)   vs daily_metrics (google)
 *      ad_campaign_daily (tiktok)   vs daily_metrics (tiktok)
 *   3. ad_creative_daily (meta)     vs ad_campaign_daily (meta)         — creatives roll up to campaigns
 *   4. ad_set_daily (meta/google/tiktok) vs ad_campaign_daily (same platform)
 *
 * Thresholds (of the reference side): >1% amber, >5% red. ≤1% is ok.
 */
final class AdsSpendRecon
{
    public const AMBER_PCT = 1.0;
    public const RED_PCT   = 5.0;

    /** Platforms that carry ad spend across the daily/campaign/adset tables. */
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /**
     * Reconcile every invariant pair for one brand over [from, to] (Y-m-d).
     *
     * @return array{
     *   brandId:int, brandName:string, from:string, to:string,
     *   pairs: array<int, array{
     *     key:string, label:string, actualTable:string, referenceTable:string, platform:string,
     *     actualTotal:float, referenceTotal:float, diff:float, driftPct:?float, level:string,
     *     days: array<int, array{date:string, actual:float, reference:float, diff:float, driftPct:?float, level:string}>
     *   }>,
     *   worstLevel:string
     * }
     */
    public function forBrand(Brand $brand, string $from, string $to): array
    {
        $pairs = [];

        // 1 + 2(meta): the incident pair, plus per-platform campaign↔account.
        $pairs[] = $this->pair('product_vs_account', 'ad_product_daily → daily_metrics (Meta attribution ≡ account spend)',
            'ad_product_daily', 'meta', 'daily_metrics', 'meta', $brand, $from, $to);

        foreach (self::AD_PLATFORMS as $platform) {
            $pairs[] = $this->pair("campaign_vs_account:{$platform}", "ad_campaign_daily → daily_metrics ({$platform})",
                'ad_campaign_daily_metrics', $platform, 'daily_metrics', $platform, $brand, $from, $to);
        }

        // 3: creatives roll up to campaigns (Meta only carries ad_creative_daily).
        $pairs[] = $this->pair('creative_vs_campaign:meta', 'ad_creative_daily → ad_campaign_daily (meta)',
            'ad_creative_daily', 'meta', 'ad_campaign_daily_metrics', 'meta', $brand, $from, $to);

        // 4: ad sets / ad groups roll up to campaigns, per platform.
        foreach (self::AD_PLATFORMS as $platform) {
            $pairs[] = $this->pair("adset_vs_campaign:{$platform}", "ad_set_daily → ad_campaign_daily ({$platform})",
                'ad_set_daily_metrics', $platform, 'ad_campaign_daily_metrics', $platform, $brand, $from, $to);
        }

        $worst = 'ok';
        foreach ($pairs as $p) {
            if ($p['level'] === 'red') {
                $worst = 'red';
                break;
            }
            if ($p['level'] === 'amber') {
                $worst = 'amber';
            }
        }

        return [
            'brandId'    => $brand->id,
            'brandName'  => $brand->name,
            'from'       => $from,
            'to'         => $to,
            'pairs'      => $pairs,
            'worstLevel' => $worst,
        ];
    }

    /**
     * One invariant pair: sum the actual table and the reference table per day,
     * compute the diff and drift %, grade each day and the window total.
     *
     * @return array<string, mixed>
     */
    private function pair(string $key, string $label, string $actualTable, string $actualPlatform, string $referenceTable, string $referencePlatform, Brand $brand, string $from, string $to): array
    {
        $actualByDay = $this->sumByDate($actualTable, $brand->id, $actualPlatform, $from, $to);
        $refByDay    = $this->sumByDate($referenceTable, $brand->id, $referencePlatform, $from, $to);

        $dates = array_keys($actualByDay + $refByDay);
        sort($dates);

        $days = [];
        $actualTotal = 0.0;
        $refTotal    = 0.0;
        foreach ($dates as $date) {
            $a = round((float) ($actualByDay[$date] ?? 0.0), 2);
            $r = round((float) ($refByDay[$date] ?? 0.0), 2);
            $actualTotal += $a;
            $refTotal    += $r;
            $drift = $this->driftPct($a, $r);
            $days[] = [
                'date'      => $date,
                'actual'    => $a,
                'reference' => $r,
                'diff'      => round($a - $r, 2),
                'driftPct'  => $drift,
                'level'     => $this->grade($drift),
            ];
        }

        $actualTotal = round($actualTotal, 2);
        $refTotal    = round($refTotal, 2);
        $totalDrift  = $this->driftPct($actualTotal, $refTotal);

        return [
            'key'            => $key,
            'label'          => $label,
            'actualTable'    => $actualTable,
            'referenceTable' => $referenceTable,
            'platform'       => $actualPlatform,
            'actualTotal'    => $actualTotal,
            'referenceTotal' => $refTotal,
            'diff'           => round($actualTotal - $refTotal, 2),
            'driftPct'       => $totalDrift,
            'level'          => $this->grade($totalDrift),
            'days'           => $days,
        ];
    }

    /**
     * Σ spend per date for one (table, brand, platform) over the window.
     *
     * @return array<string, float> date (Y-m-d) => spend
     */
    private function sumByDate(string $table, int $brandId, string $platform, string $from, string $to): array
    {
        $rows = DB::table($table)
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')
            ->selectRaw('date, COALESCE(SUM(spend), 0) AS spend')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            // SQLite returns 'date' as 'Y-m-d', MySQL/pg as a datetime string — take
            // the first 10 chars so the two sides key identically.
            $out[substr((string) $r->date, 0, 10)] = (float) $r->spend;
        }

        return $out;
    }

    /**
     * Drift of `actual` from the reference-of-truth, as a percent of the
     * reference. Null when there is genuinely nothing to compare (both zero).
     * When the reference is zero but actual isn't, that's spend with no source of
     * truth → treated as a full 100% red drift, never silently 0.
     */
    private function driftPct(float $actual, float $reference): ?float
    {
        if ($reference == 0.0) {
            return $actual == 0.0 ? 0.0 : 100.0;
        }

        return round(abs($actual - $reference) / abs($reference) * 100, 3);
    }

    private function grade(?float $driftPct): string
    {
        if ($driftPct === null) {
            return 'ok';
        }
        if ($driftPct > self::RED_PCT) {
            return 'red';
        }
        if ($driftPct > self::AMBER_PCT) {
            return 'amber';
        }

        return 'ok';
    }
}
