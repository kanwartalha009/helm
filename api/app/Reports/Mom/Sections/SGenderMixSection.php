<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
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

        $cur = $this->metrics($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Meta age/gender-breakdown data synced for this brand/month yet (meta:backfill-breakdown --type=age_gender).',
            ];
        }

        $compareWindow = $filters->compareMonthWindow($tz);
        $cmp = $compareWindow !== null ? $this->metrics($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'male' => [
                'spend' => $cur['male'], 'pct' => $cur['malePct'],
                'compare' => $cmp['male'] ?? null, 'comparePct' => $cmp['malePct'] ?? null,
            ],
            'female' => [
                'spend' => $cur['female'], 'pct' => $cur['femalePct'],
                'compare' => $cmp['female'] ?? null, 'comparePct' => $cmp['femalePct'] ?? null,
            ],
            'unavailable' => $cur['unknown'] > 0.0 ? [
                'note' => round($cur['unknown'], 2) . ' spend on unknown-gender rows excluded from the split.',
            ] : null,
        ];
    }

    /** @return array{male: float, female: float, unknown: float, malePct: ?float, femalePct: ?float}|null */
    private function metrics(int $brandId, string $start, string $end): ?array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->where('breakdown_type', 'age_gender')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key')
            ->selectRaw('segment_key, COALESCE(SUM(spend), 0) AS spend')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $male = 0.0;
        $female = 0.0;
        $unknown = 0.0;
        foreach ($rows as $r) {
            $k = strtolower((string) $r->segment_key);
            $spend = (float) $r->spend;
            if (str_contains($k, 'female')) {
                $female += $spend;
            } elseif (str_contains($k, 'male')) {
                $male += $spend;
            } else {
                $unknown += $spend;
            }
        }

        $known = $male + $female;

        return [
            'male' => round($male, 2),
            'female' => round($female, 2),
            'unknown' => round($unknown, 2),
            'malePct' => $known > 0.0 ? round($male / $known * 100, 1) : null,
            'femalePct' => $known > 0.0 ? round($female / $known * 100, 1) : null,
        ];
    }
}
