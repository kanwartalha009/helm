<?php

declare(strict_types=1);

namespace App\Services\AdsLibrary;

use App\Models\AdCreativeDaily;
use App\Reports\Support\AdAudit;
use Carbon\CarbonImmutable;

/**
 * Pattern benchmarks (Ads Library Phase 4.3) — the moat. For each tag with ≥3
 * tagged INTERNAL creatives clearing the $50 evidence floor, the Verified median
 * ROAS/CTR from ad_creative_daily. Deterministic SQL, no LLM. Honest below 3.
 *
 * Product lens (D-022): this reads the caller's OWN board items (their own ads) —
 * it never pools one tenant's performance into another's benchmark.
 */
class TagBenchmarks
{
    /**
     * @param array<string, list<string>> $tagToAdIds  tag => internal ad_ids
     * @return list<array{tag: string, count: int, medianRoas: ?float, medianCtr: ?float, enough: bool}>
     */
    public function forAdIds(array $tagToAdIds): array
    {
        $allIds = array_values(array_unique(array_merge([], ...array_values($tagToAdIds))));
        if ($allIds === []) {
            return [];
        }

        $end   = CarbonImmutable::now()->subDay();
        $start = $end->subDays(89);
        $rows = AdCreativeDaily::query()
            ->whereIn('ad_id', $allIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('ad_id')
            ->selectRaw(
                'ad_id,'
                . 'COALESCE(SUM(spend * COALESCE(fx_rate_to_usd, 1)), 0) AS spend_usd,'
                . 'COALESCE(SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)), 0) AS rev_usd,'
                . 'COALESCE(SUM(impressions), 0) AS impr,'
                . 'COALESCE(SUM(clicks), 0) AS clk'
            )
            ->get()
            ->keyBy('ad_id');

        $roasByAd = [];
        $ctrByAd  = [];
        foreach ($rows as $id => $r) {
            if ((float) $r->spend_usd < AdAudit::MIN_SPEND) {
                continue; // evidence floor
            }
            $roasByAd[(string) $id] = (float) $r->spend_usd > 0 ? (float) $r->rev_usd / (float) $r->spend_usd : null;
            $ctrByAd[(string) $id]  = (int) $r->impr > 0 ? (int) $r->clk / (int) $r->impr * 100 : null;
        }

        $out = [];
        foreach ($tagToAdIds as $tag => $ids) {
            $roas = array_values(array_filter(array_map(fn ($i) => $roasByAd[$i] ?? null, $ids), fn ($v) => $v !== null));
            $ctr  = array_values(array_filter(array_map(fn ($i) => $ctrByAd[$i] ?? null, $ids), fn ($v) => $v !== null));
            $n = count($roas);
            $out[] = [
                'tag'        => $tag,
                'count'      => $n,
                'medianRoas' => $n >= 3 ? round($this->median($roas), 2) : null,
                'medianCtr'  => $n >= 3 ? round($this->median($ctr), 2) : null,
                'enough'     => $n >= 3,
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['medianRoas'] ?? -1) <=> ($a['medianRoas'] ?? -1));

        return $out;
    }

    /** @param list<float> $v */
    private function median(array $v): float
    {
        sort($v);
        $n = count($v);
        if ($n === 0) {
            return 0.0;
        }
        $m = intdiv($n, 2);

        return $n % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    }
}
