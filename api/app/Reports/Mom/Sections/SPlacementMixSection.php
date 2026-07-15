<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use Carbon\CarbonImmutable;

/**
 * M3 (monthly-report-v2-mom.md §M3) — "S14 Placement mix (slide 11). Placement
 * x (cost, CPC, CTR, CPM, %spend, acc%) from the placement breakdown axis +
 * 'vertical placement %' (stories+reels) vs Goal >80% chip + Feed-vs-Stories/
 * Reels delta mini-table. Needs meta:backfill-breakdown placement coverage —
 * backfill CTA when missing."
 *
 * Reads `meta_breakdown_daily` breakdown_type='placement' (publisher_platform x
 * platform_position — the granular axis; config/meta_breakdowns.php documents
 * two placement axes, this section uses the detailed one since the spec wants
 * per-position CPC/CTR/CPM). "Vertical" (Stories+Reels) detection is a
 * case-insensitive substring match on the segment key/label for "stor"/"reel" —
 * the same substring-classification pattern already established in this
 * codebase's `AudienceQuery::genderTotals()`, since the exact platform_position
 * string vocabulary Meta returns isn't pinned down anywhere in this session.
 */
final class SPlacementMixSection implements MomSection
{
    public function key(): string
    {
        return 'S14';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->metrics($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Meta placement-breakdown data synced for this brand/month yet (meta:backfill-breakdown --type=placement).',
            ];
        }

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $this->metrics($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $goal = (float) config('momreport.benchmarks.vertical_placement_pct_goal', 80.0);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'verticalPct' => [
                'value'    => $cur['verticalPct'],
                'compare'  => $cmp['verticalPct'] ?? null,
                'deltaPct' => $this->delta($cur['verticalPct'], $cmp['verticalPct'] ?? null),
            ],
            'goal' => $goal,
            'goalHit' => $cur['verticalPct'] !== null ? $cur['verticalPct'] >= $goal : null,
            // Feed vs Stories/Reels delta mini-table (REV2 R1's "delta mini-table").
            'feedVsVertical' => [
                'feedPct'     => $cur['feedPct'],
                'verticalPct' => $cur['verticalPct'],
            ],
            'rows' => $cur['rows'],
        ];
    }

    /** @return array{verticalPct: ?float, feedPct: ?float, rows: array<int, array<string, mixed>>}|null */
    private function metrics(int $brandId, string $start, string $end): ?array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->where('breakdown_type', 'placement')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key', 'segment_label')
            ->selectRaw('segment_key, MAX(segment_label) AS label,
                COALESCE(SUM(spend), 0) AS spend,
                COALESCE(SUM(impressions), 0) AS impressions,
                COALESCE(SUM(clicks), 0) AS clicks')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $total = (float) $rows->sum('spend');
        $sorted = $rows->sortByDesc('spend')->values();

        $out = [];
        $vertical = 0.0;
        $feed = 0.0;
        $cumSpend = 0.0;
        foreach ($sorted as $r) {
            $key    = (string) $r->segment_key;
            $label  = (string) ($r->label ?: $key);
            $spend  = round((float) $r->spend, 2);
            $clicks = (int) $r->clicks;
            $imps   = (int) $r->impressions;
            $isVertical = $this->isVertical($key) || $this->isVertical($label);
            if ($isVertical) {
                $vertical += (float) $r->spend;
            } elseif ($this->isFeed($key) || $this->isFeed($label)) {
                $feed += (float) $r->spend;
            }
            $cumSpend += (float) $r->spend;

            $out[] = [
                'key' => $key, 'label' => $label, 'spend' => $spend,
                'cpc' => $clicks > 0 ? round((float) $r->spend / $clicks, 2) : null,
                'ctr' => $imps > 0 ? round($clicks / $imps * 100, 2) : null,
                'cpm' => $imps > 0 ? round((float) $r->spend / $imps * 1000, 2) : null,
                'pctSpend' => $total > 0.0 ? round((float) $r->spend / $total * 100, 1) : null,
                'accPct'   => $total > 0.0 ? round($cumSpend / $total * 100, 1) : null,
                'isVertical' => $isVertical,
            ];
        }

        return [
            'verticalPct' => $total > 0.0 ? round($vertical / $total * 100, 1) : null,
            'feedPct'     => $total > 0.0 ? round($feed / $total * 100, 1) : null,
            'rows'        => $out,
        ];
    }

    private function isVertical(string $s): bool
    {
        $s = strtolower($s);

        return str_contains($s, 'stor') || str_contains($s, 'reel');
    }

    private function isFeed(string $s): bool
    {
        return str_contains(strtolower($s), 'feed');
    }

    private function delta(?float $value, ?float $compare): ?float
    {
        if ($value === null || $compare === null || $compare === 0.0) {
            return null;
        }

        return round(($value - $compare) / $compare * 100, 1);
    }
}
