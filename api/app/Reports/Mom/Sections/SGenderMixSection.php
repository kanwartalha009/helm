<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Reports\Mom\Support\MetaBreakdownMetrics;
use Carbon\CarbonImmutable;

/**
 * M3 (monthly-report-v2-mom.md §M3) — "S15 Gender mix (slide 13) — age_gender
 * axis folded to gender (reuse genderRows)."
 *
 * Folds `meta_breakdown_daily` breakdown_type='age_gender' segment keys (e.g.
 * "25-34 · female") down to a two-way Male/Female spend split via a
 * case-insensitive substring match — the exact logic the existing Audience
 * dashboard uses (`AudienceQuery::genderTotals()`), reimplemented
 * independently here per REV2 R7 (no v1/shared dashboard files touched).
 * Unknown-gender rows are excluded from the split (same as the dashboard) —
 * the split is about the two known audiences, not a fabricated third bucket.
 */
final class SGenderMixSection implements MomSection
{
    public function key(): string
    {
        return 'S15';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->monthWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        // Detailed per-gender ad metrics (Kanwar, 2026-07-16 — "should look like
        // this"): Cost/Reach/Freq/Clicks/CTR/CPM/Purch/ROAS/CPA/Share. Folds the
        // age_gender axis to Male/Female/Unknown, summing EVERY metric (not just
        // spend), then derives the rates via the shared MetaBreakdownMetrics.
        $platform = in_array($filters->platform, ['meta', 'tiktok'], true) ? $filters->platform : 'meta';
        $svc = new MetaBreakdownMetrics();
        $platforms = $svc->availablePlatforms($brand->id, 'age_gender', $start, $end);
        $raw = $svc->rawSegments($brand->id, $platform, 'age_gender', $start, $end);
        if ($raw === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => "No {$platform} age/gender-breakdown data synced for this brand/month yet (meta:backfill-breakdown --type=age_gender).",
                'platform' => $platform,
                'availablePlatforms' => $platforms,
            ];
        }

        // Pre-seed the two KNOWN genders so both always render even at an honest
        // zero (Kanwar, 2026-07-17 — "even if male is zero show male row with
        // zero"). We only reach here when the axis HAS data, so this surfaces an
        // empty gender as a real €0 row, never fabricates a table out of nothing.
        // 'unknown' stays dynamic — it only appears when Meta actually returns it.
        $buckets = [
            'male'   => ['label' => 'Male', 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'reach' => null, 'purchases' => 0, 'convValue' => 0.0],
            'female' => ['label' => 'Female', 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'reach' => null, 'purchases' => 0, 'convValue' => 0.0],
        ];
        $total = 0.0;
        foreach ($raw as $key => $s) {
            $k = strtolower($key . ' ' . $s['label']);
            $bucket = str_contains($k, 'female') ? 'female' : (str_contains($k, 'male') ? 'male' : 'unknown');
            $buckets[$bucket] ??= ['label' => ucfirst($bucket), 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'reach' => null, 'purchases' => 0, 'convValue' => 0.0];
            $buckets[$bucket]['spend']       += $s['spend'];
            $buckets[$bucket]['impressions'] += $s['impressions'];
            $buckets[$bucket]['clicks']      += $s['clicks'];
            $buckets[$bucket]['purchases']   += $s['purchases'];
            $buckets[$bucket]['convValue']   += $s['convValue'];
            if ($s['reach'] !== null) {
                $buckets[$bucket]['reach'] = ($buckets[$bucket]['reach'] ?? 0) + $s['reach'];
            }
            $total += $s['spend'];
        }

        $rows = [];
        foreach ($buckets as $key => $b) {
            $rows[] = ['key' => $key, 'label' => $b['label']] + $svc->metrics($b, $total);
        }
        usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'platform' => $platform,
            'availablePlatforms' => $platforms,
            'rows'   => $rows,
        ];
    }
}
