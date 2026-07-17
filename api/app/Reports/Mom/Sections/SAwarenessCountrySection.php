<?php

declare(strict_types=1);

namespace App\Reports\Mom\Sections;

use App\Models\Brand;
use App\Models\MetaBreakdownDaily;
use App\Reports\Contracts\ReportFilters;
use App\Reports\Mom\Contracts\MomSection;
use App\Support\CountryCodes;
use Carbon\CarbonImmutable;

/**
 * M3 addendum (monthly-report-v2-mom.md §M3) — "S16 Thruplay/awareness
 * country concentration (slide 14): country breakdown filtered to awareness
 * campaigns (campaign objective from ad_campaign_daily_metrics), concentration
 * alert when top country > threshold." Deliberately left unregistered through
 * M3/M5 (real schema gap: no objective column existed anywhere) — this pass
 * closes that gap (see the objective column's migration + MetaObjectives) and
 * builds S16 on top of a new meta_breakdown_daily axis, 'awareness_country'
 * (App\Services\Sync\CampaignSync::syncMetaAwarenessCountry /
 * meta:backfill-awareness-country), rather than a live API call at report
 * time — same "read from already-synced tables" architecture every other
 * section follows.
 *
 * HONESTY CAVEAT (same discipline as the S1 customer_type probe): the sync
 * path this reads from depends on Meta's `objective` field and campaign.id
 * filtering behaving the way MetaObjectives/InsightsFetcher assume — not
 * verified against a live Meta API response in this sandbox. If the sync
 * never populates 'awareness_country' rows for a brand (wrong assumption, or
 * the brand genuinely runs no awareness campaigns), this section renders
 * `needs_source` honestly rather than a fake empty concentration reading.
 */
final class SAwarenessCountrySection implements MomSection
{
    public function key(): string
    {
        return 'S16';
    }

    public function build(Brand $brand, ReportFilters $filters): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $window = $filters->activeWindow($tz);
        if ($window === null) {
            return ['key' => $this->key(), 'status' => 'no_data', 'note' => 'No complete month selected.'];
        }
        [$start, $end] = $window;

        $cur = $this->countrySpend($brand->id, $start, $end);
        if ($cur === null) {
            return [
                'key'    => $this->key(),
                'status' => 'needs_source',
                'note'   => 'No Meta awareness-campaign country data synced for this brand/month yet (meta:backfill-awareness-country — needs ads:backfill-campaigns run first so a campaign objective exists to filter on).',
            ];
        }

        $compareWindow = $filters->activeComparisonWindow($tz);
        $cmp = $compareWindow !== null ? $this->countrySpend($brand->id, $compareWindow[0], $compareWindow[1]) : null;

        $threshold = (float) config('momreport.benchmarks.awareness_country_concentration_pct', 50.0);
        $topSharePct = $cur['rows'] !== [] ? $cur['rows'][0]['sharePct'] : null;
        $cmpTopSharePct = ($cmp !== null && $cmp['rows'] !== []) ? $cmp['rows'][0]['sharePct'] : null;

        return [
            'key'    => $this->key(),
            'status' => 'ok',
            'month'  => CarbonImmutable::parse($start)->format('Y-m'),
            'compareMonth' => $compareWindow !== null ? CarbonImmutable::parse($compareWindow[0])->format('Y-m') : null,
            'totalSpend' => $this->tile($cur['total'], $cmp['total'] ?? null),
            'topCountry' => $cur['rows'] !== [] ? $cur['rows'][0]['label'] : null,
            'topSharePct' => $this->tile($topSharePct, $cmpTopSharePct),
            'threshold' => $threshold,
            // Alert when awareness spend concentrates in one market above the
            // threshold — null (never a false "pass") when there's no spend to
            // rate at all.
            'alert' => $topSharePct !== null ? $topSharePct > $threshold : null,
            'rows' => $cur['rows'],
            'unavailable' => [
                'liveApiVerification' => 'The objective + campaign.id-filter assumptions this data depends on have not been verified against a live Meta API response in this sandbox — see the class docblock.',
            ],
        ];
    }

    /** @return array{total: float, rows: array<int, array<string, mixed>>}|null */
    private function countrySpend(int $brandId, string $start, string $end): ?array
    {
        $rows = MetaBreakdownDaily::query()
            ->where('brand_id', $brandId)
            ->where('platform', 'meta')
            ->where('breakdown_type', 'awareness_country')
            ->whereBetween('date', [$start, $end])
            ->groupBy('segment_key')
            ->selectRaw('segment_key, COALESCE(SUM(spend), 0) AS spend, COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $total = 0.0;
        $out   = [];
        foreach ($rows as $r) {
            $spend = round((float) $r->spend, 2);
            $total += $spend;
            $iso2 = CountryCodes::toIso2((string) $r->segment_key);
            $out[] = [
                'iso2'        => $iso2 ?? (string) $r->segment_key,
                'label'       => $iso2 ?? (string) $r->segment_key,
                'spend'       => $spend,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
            ];
        }

        foreach ($out as &$row) {
            $row['sharePct'] = $total > 0.0 ? round($row['spend'] / $total * 100, 1) : null;
        }
        unset($row);

        usort($out, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return ['total' => round($total, 2), 'rows' => $out];
    }

    /** @return array{value: ?float, compare: ?float, deltaPct: ?float} */
    private function tile(?float $value, ?float $compareValue): array
    {
        $deltaPct = null;
        if ($value !== null && $compareValue !== null && $compareValue !== 0.0) {
            $deltaPct = round(($value - $compareValue) / $compareValue * 100, 1);
        }

        return ['value' => $value, 'compare' => $compareValue, 'deltaPct' => $deltaPct];
    }
}
