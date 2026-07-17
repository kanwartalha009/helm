<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\MetaBreakdownMetrics;
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
        $window = $filters->activeWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        // Detailed per-placement ad metrics (Kanwar, 2026-07-16 — "should look
        // like this detailed columns"): Cost/Reach/Freq/Clicks/CTR/CPM/Purch/
        // ROAS/CPA/Share, via the shared MetaBreakdownMetrics. Platform-switchable
        // (Meta default; TikTok when its breakdown is synced).
        $platform = in_array($filters->platform, ['meta', 'tiktok'], true) ? $filters->platform : 'meta';
        $svc = new MetaBreakdownMetrics();
        $platforms = $svc->availablePlatforms($brand->id, 'placement', $start, $end);
        $raw = $svc->rawSegments($brand->id, $platform, 'placement', $start, $end);
        if ($raw === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => "No {$platform} placement-breakdown data synced for this brand/month yet (meta:backfill-breakdown --type=placement).",
                'platform' => $platform,
                'availablePlatforms' => $platforms,
            ];
        }

        $total = 0.0;
        foreach ($raw as $s) {
            $total += $s['spend'];
        }

        $vertical = 0.0;
        $feed = 0.0;
        $rows = [];
        foreach ($raw as $key => $s) {
            if ($this->isVertical($key) || $this->isVertical($s['label'])) {
                $vertical += $s['spend'];
            } elseif ($this->isFeed($key) || $this->isFeed($s['label'])) {
                $feed += $s['spend'];
            }
            $rows[] = ['key' => $key, 'label' => $s['label']] + $svc->metrics($s, $total);
        }
        usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $goal = (float) config('momreport.benchmarks.vertical_placement_pct_goal', 80.0);
        $verticalPct = $total > 0.0 ? round($vertical / $total * 100, 1) : null;

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'platform' => $platform,
            'availablePlatforms' => $platforms,
            'verticalPct' => ['value' => $verticalPct],
            'goal' => $goal,
            'goalHit' => $verticalPct !== null ? $verticalPct >= $goal : null,
            'feedVsVertical' => [
                'feedPct'     => $total > 0.0 ? round($feed / $total * 100, 1) : null,
                'verticalPct' => $verticalPct,
            ],
            'rows' => $rows,
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
