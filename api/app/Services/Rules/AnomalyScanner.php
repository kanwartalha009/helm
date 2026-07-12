<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\Anomaly;
use App\Models\Brand;
use App\Models\DailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The anomaly scanner (GO-2.4, master plan §5.4). Deterministic rules only.
 *
 * Every anomaly answers: "this number moved X% against its own trailing 28-day MEDIAN,
 * and the threshold is Y%." A human can re-derive any alert by hand from the evidence
 * json. That is the whole design: an alert stream nobody trusts is worse than no
 * alerts, because it teaches people to ignore the one that mattered.
 *
 * Two guards run before every rule:
 *  - MEDIAN, not mean. One Black Friday would drag a mean far enough to suppress real
 *    alerts for weeks.
 *  - `min_days` of COMPLETE history, or the rule stays silent. With three days of data
 *    there is no baseline, and a confident alert computed from noise is a wrong number.
 *
 * Writes are idempotent (unique brand+date+kind+subject): re-scanning a day updates the
 * evidence instead of stacking duplicates.
 */
class AnomalyScanner
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /**
     * Scan one brand for one day and persist what fired. Returns the rows written.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scan(Brand $brand, ?CarbonImmutable $date = null): array
    {
        $tz   = $brand->timezone ?: 'UTC';
        $day  = ($date ?? CarbonImmutable::now($tz)->subDay())->startOfDay();
        $cfg  = (array) config('anomalies.rules', []);
        $win  = (int) config('anomalies.window_days', 28);
        $minD = (int) config('anomalies.min_days', 14);

        $found = [];

        $connected = $brand->connections()->where('status', 'active')->pluck('platform')->unique()->all();
        $adPlatforms = array_values(array_intersect($connected, self::AD_PLATFORMS));

        foreach ($adPlatforms as $platform) {
            $history = $this->platformHistory($brand->id, $platform, $day->subDays($win), $day->subDay());
            $todayRow = $this->platformDay($brand->id, $platform, $day);

            // zero_delivery: the platform spent money on most baseline days and spent
            // nothing today. Fires even against a thin-ish baseline because "it stopped"
            // is the one signal you cannot afford to miss (billing failure, paused acct).
            if (($cfg['zero_delivery']['enabled'] ?? false) && count($history) >= $minD) {
                $spendingDays = count(array_filter($history, static fn (array $r): bool => $r['spend'] > 0));
                $todaySpend   = $todayRow['spend'] ?? 0.0;
                if ($spendingDays >= $minD && $todaySpend <= 0.0) {
                    $found[] = $this->row($brand, $day, 'zero_delivery', $platform, (string) $cfg['zero_delivery']['severity'], [
                        'rule'      => 'zero_delivery',
                        'platform'  => $platform,
                        'todaySpend' => 0,
                        'spendingDaysInWindow' => $spendingDays,
                        'windowDays' => count($history),
                        'note'      => 'This platform spent on ' . $spendingDays . ' of the last ' . count($history)
                            . ' days and spent nothing on ' . $day->toDateString() . '. Usually a paused campaign, a billing failure, or a broken connection.',
                    ]);
                }
            }

            if ($todayRow === null || count($history) < $minD) {
                continue; // no baseline → no ratio rules. Silence beats a guess.
            }

            // CPM / CPA / ROAS / spend, each against its own 28-day median.
            $this->ratioRule($found, $brand, $day, $platform, 'cpm_spike', $cfg,
                $todayRow['cpm'], $this->median(array_column($history, 'cpm')), 'up');

            $this->ratioRule($found, $brand, $day, $platform, 'cpa_spike', $cfg,
                $todayRow['cpa'], $this->median(array_column($history, 'cpa')), 'up');

            $this->ratioRule($found, $brand, $day, $platform, 'roas_drop', $cfg,
                $todayRow['roas'], $this->median(array_column($history, 'roas')), 'down');

            $this->ratioRule($found, $brand, $day, $platform, 'spend_spike', $cfg,
                $todayRow['spend'] > 0 ? $todayRow['spend'] : null,
                $this->median(array_column($history, 'spend')), 'up');
        }

        // Brand-level rules.
        $this->stockoutOnAds($found, $brand, $day, $cfg);
        $this->merDivergence($found, $brand, $day, $cfg, $win, $minD);

        // Persist idempotently — a re-scan refreshes evidence, never duplicates.
        foreach ($found as $row) {
            Anomaly::updateOrCreate(
                [
                    'brand_id' => $row['brand_id'],
                    'date'     => $row['date'],
                    'kind'     => $row['kind'],
                    'subject'  => $row['subject'],
                ],
                [
                    'severity' => $row['severity'],
                    'evidence' => $row['evidence'],
                ],
            );
        }

        return $found;
    }

    /**
     * One ratio rule: today vs its trailing median, in the direction that is BAD.
     *
     * @param array<int, array<string, mixed>> $found
     * @param array<string, mixed> $cfg
     */
    private function ratioRule(
        array &$found, Brand $brand, CarbonImmutable $day, string $platform,
        string $kind, array $cfg, ?float $actual, ?float $median, string $direction,
    ): void {
        $rule = $cfg[$kind] ?? null;
        if (! ($rule['enabled'] ?? false) || $actual === null || $median === null || $median <= 0.0) {
            return; // no baseline, or nothing measured today → stay silent
        }

        $threshold = (float) $rule['threshold_pct'];
        $deltaPct  = ($actual - $median) / $median * 100;

        $fired = $direction === 'up' ? $deltaPct >= $threshold : $deltaPct <= -$threshold;
        if (! $fired) {
            return;
        }

        $found[] = $this->row($brand, $day, $kind, $platform, (string) $rule['severity'], [
            'rule'         => $kind,
            'platform'     => $platform,
            'actual'       => round($actual, 2),
            'median28d'    => round($median, 2),
            'deltaPct'     => round($deltaPct, 1),
            'thresholdPct' => $threshold,
            'direction'    => $direction,
        ]);
    }

    /**
     * Ad money spent driving traffic to a product that cannot be bought.
     *
     * @param array<int, array<string, mixed>> $found
     * @param array<string, mixed> $cfg
     */
    private function stockoutOnAds(array &$found, Brand $brand, CarbonImmutable $day, array $cfg): void
    {
        $rule = $cfg['stockout_on_ads'] ?? null;
        if (! ($rule['enabled'] ?? false)) {
            return;
        }

        $since   = $day->subDays((int) $rule['lookback_days'] - 1)->toDateString();
        $minSpend = (float) $rule['min_spend_usd'];

        $spendByHandle = DB::table('ad_product_daily')
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$since, $day->toDateString()])
            ->groupBy('product_key')
            ->selectRaw('product_key, COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS spend_usd')
            ->having('spend_usd', '>=', $minSpend)
            ->pluck('spend_usd', 'product_key');

        if ($spendByHandle->isEmpty()) {
            return;
        }

        $outOfStock = DB::table('product_catalog')
            ->where('brand_id', $brand->id)
            ->whereIn('handle', $spendByHandle->keys()->all())
            ->where('total_inventory', '<=', 0)
            ->pluck('title', 'handle');

        foreach ($outOfStock as $handle => $title) {
            $found[] = $this->row($brand, $day, 'stockout_on_ads', (string) $handle, (string) $rule['severity'], [
                'rule'          => 'stockout_on_ads',
                'product'       => $title ?: $handle,
                'handle'        => $handle,
                'spendUsd'      => round((float) $spendByHandle[$handle], 2),
                'lookbackDays'  => (int) $rule['lookback_days'],
                'totalInventory' => 0,
                'note'          => 'Ads are spending on a product with no stock — the traffic cannot convert.',
            ]);
        }
    }

    /**
     * Tracking health: the ratio of platform-CLAIMED revenue to store-truth revenue,
     * moving sharply against its own baseline. A jump here is almost always a pixel/CAPI
     * break or an attribution-window change — a signal about the DATA, not the ads.
     *
     * @param array<int, array<string, mixed>> $found
     * @param array<string, mixed> $cfg
     */
    private function merDivergence(array &$found, Brand $brand, CarbonImmutable $day, array $cfg, int $win, int $minD): void
    {
        $rule = $cfg['mer_divergence'] ?? null;
        if (! ($rule['enabled'] ?? false)) {
            return;
        }

        $ratios = [];
        $todayRatio = null;

        for ($i = 0; $i <= $win; $i++) {
            $d = $day->subDays($i);
            $store = (float) DailyMetric::query()
                ->where('brand_id', $brand->id)->where('platform', 'shopify')->where('is_complete', true)
                ->where('date', $d->toDateString())
                ->selectRaw('COALESCE(SUM(COALESCE(total_sales,0) + COALESCE(refunds_amount,0)), 0) AS v')->value('v');
            $claimed = (float) DailyMetric::query()
                ->where('brand_id', $brand->id)->whereIn('platform', self::AD_PLATFORMS)
                ->where('date', $d->toDateString())
                ->selectRaw('COALESCE(SUM(COALESCE(conversion_value,0)), 0) AS v')->value('v');

            if ($store <= 0.0) {
                continue; // no store revenue → the ratio is undefined, not zero
            }
            $ratio = $claimed / $store;

            if ($i === 0) {
                $todayRatio = $ratio;
            } else {
                $ratios[] = $ratio;
            }
        }

        $median = $this->median($ratios);
        if ($todayRatio === null || $median === null || $median <= 0.0 || count($ratios) < $minD) {
            return;
        }

        $threshold = (float) $rule['threshold_pct'];
        $deltaPct  = ($todayRatio - $median) / $median * 100;

        if (abs($deltaPct) < $threshold) {
            return;
        }

        $found[] = $this->row($brand, $day, 'mer_divergence', '', (string) $rule['severity'], [
            'rule'          => 'mer_divergence',
            'claimedOverStoreToday' => round($todayRatio, 2),
            'median28d'     => round($median, 2),
            'deltaPct'      => round($deltaPct, 1),
            'thresholdPct'  => $threshold,
            'note'          => 'The gap between what the ad platforms claim they drove and what the store actually took '
                . 'moved sharply. This is usually a tracking break (pixel/CAPI) or an attribution-window change — '
                . 'a signal about the DATA, not about the ads.',
        ]);
    }

    /**
     * Per-day metrics for a platform across a window (complete rows only).
     *
     * @return array<int, array<string, mixed>>
     */
    private function platformHistory(int $brandId, string $platform, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['date', 'spend', 'impressions', 'conversions', 'conversion_value']);

        return $rows->map(fn ($r): array => $this->metrics($r))->all();
    }

    /** @return array<string, mixed>|null */
    private function platformDay(int $brandId, string $platform, CarbonImmutable $day): ?array
    {
        $r = DailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('date', $day->toDateString())
            ->first(['date', 'spend', 'impressions', 'conversions', 'conversion_value']);

        return $r === null ? null : $this->metrics($r);
    }

    /** @return array<string, mixed> */
    private function metrics(object $r): array
    {
        $spend  = (float) ($r->spend ?? 0);
        $impr   = (int) ($r->impressions ?? 0);
        $conv   = (int) ($r->conversions ?? 0);
        $value  = (float) ($r->conversion_value ?? 0);

        return [
            'spend' => $spend,
            // Missing ≠ zero: no impressions → no CPM (not a CPM of 0).
            'cpm'   => $impr > 0 ? $spend / $impr * 1000 : null,
            'cpa'   => $conv > 0 ? $spend / $conv : null,
            'roas'  => $spend > 0 ? $value / $spend : null,
        ];
    }

    /**
     * Median of the non-null values. Null when there is nothing to take a median of.
     *
     * @param array<int, float|null> $values
     */
    private function median(array $values): ?float
    {
        $v = array_values(array_filter($values, static fn ($x): bool => $x !== null));
        if ($v === []) {
            return null;
        }
        sort($v);
        $n = count($v);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $v[$mid] : ((float) $v[$mid - 1] + (float) $v[$mid]) / 2;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function row(Brand $brand, CarbonImmutable $day, string $kind, string $subject, string $severity, array $evidence): array
    {
        return [
            'brand_id' => (int) $brand->id,
            'date'     => $day->toDateString(),
            'kind'     => $kind,
            'subject'  => $subject,
            'severity' => $severity,
            'evidence' => $evidence,
        ];
    }
}
