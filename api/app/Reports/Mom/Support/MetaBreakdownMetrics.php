<?php

declare(strict_types=1);

namespace App\Reports\Mom\Support;

use App\Models\MetaBreakdownDaily;

/**
 * The detailed per-segment ad metrics both S14 (placement) and S15 (gender) now
 * render (Kanwar, 2026-07-16 — "should look like this detailed columns"):
 * Cost, Reach, Freq, Clicks, CTR, CPM, Purchases, ROAS, CPA and spend Share —
 * the exact column set of the app's existing "Ad spend by placement / gender"
 * tables. Shared here once so the two sections read meta_breakdown_daily the
 * same way and can never drift.
 *
 * Every derived metric degrades to null (not 0) when its denominator is missing
 * — reach is nullable (added later, NULL on un-resynced rows), so freq/… stay
 * honestly blank rather than fabricating a rate from an absent base.
 */
final class MetaBreakdownMetrics
{
    /**
     * Raw per-segment sums for a breakdown axis, or null when the axis has no
     * rows in the window (caller renders needs_source, never a fake empty table).
     *
     * @return array<string, array{label: string, spend: float, impressions: int, clicks: int, reach: ?int, purchases: int, convValue: float}>|null
     */
    public function rawSegments(int $brandId, string $platform, string $breakdownType, string $start, string $end): ?array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('breakdown_type', $breakdownType)
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key', 'segment_label')
            ->selectRaw('segment_key, MAX(segment_label) AS label,
                COALESCE(SUM(spend), 0) AS spend,
                COALESCE(SUM(impressions), 0) AS impressions,
                COALESCE(SUM(clicks), 0) AS clicks,
                SUM(reach) AS reach,
                COALESCE(SUM(conversions), 0) AS purchases,
                COALESCE(SUM(conversion_value), 0) AS conv_value')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r->segment_key;
            $out[$key] = [
                'label'       => (string) ($r->label ?: $key),
                'spend'       => (float) $r->spend,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
                'reach'       => $r->reach !== null ? (int) $r->reach : null,
                'purchases'   => (int) $r->purchases,
                'convValue'   => (float) $r->conv_value,
            ];
        }

        return $out;
    }

    /**
     * Derive the render-ready metric row from one segment's raw sums.
     *
     * @param array{spend: float, impressions: int, clicks: int, reach: ?int, purchases: int, convValue: float} $s
     * @return array<string, mixed>
     */
    public function metrics(array $s, float $totalSpend): array
    {
        $spend = $s['spend'];
        $imps  = $s['impressions'];
        $reach = $s['reach'];

        return [
            'spend'     => round($spend, 2),
            'reach'     => $reach,
            'frequency' => ($reach !== null && $reach > 0) ? round($imps / $reach, 2) : null,
            'clicks'    => $s['clicks'],
            'ctr'       => $imps > 0 ? round($s['clicks'] / $imps * 100, 2) : null,
            'cpm'       => $imps > 0 ? round($spend / $imps * 1000, 2) : null,
            'purchases' => $s['purchases'],
            'roas'      => $spend > 0.0 ? round($s['convValue'] / $spend, 2) : null,
            'cpa'       => $s['purchases'] > 0 ? round($spend / $s['purchases'], 2) : null,
            'sharePct'  => $totalSpend > 0.0 ? round($spend / $totalSpend * 100, 1) : null,
        ];
    }
}
