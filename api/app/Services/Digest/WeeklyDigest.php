<?php

declare(strict_types=1);

namespace App\Services\Digest;

use App\Models\Anomaly;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Services\AdsLibrary\MarketAlerts;
use App\Services\Ledger\TrackRecord;
use Carbon\CarbonImmutable;

/**
 * The weekly digest (GO-3.5, master plan §6.5) — what actually changed, in one place.
 *
 * Four sections: new recommendations, open anomalies, the track-record delta (how Helm's
 * own advice scored this week), and competitor movement.
 *
 * ══ AN HONEST EMPTY IS A FEATURE ══
 * A quiet week says "quiet week — nothing actionable" and stops. It does not pad itself
 * with vanity metrics to look busy. A digest that always has something to say is a
 * digest people stop opening, and then the week it DOES matter, nobody reads it.
 *
 * The track-record section is the uncomfortable one on purpose: it reports what Helm got
 * WRONG this week alongside what it got right. An engine that only reports its wins in
 * its own weekly email is running a marketing campaign, not a feedback loop.
 */
class WeeklyDigest
{
    public function __construct(
        private readonly TrackRecord $trackRecord,
        private readonly MarketAlerts $marketAlerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compose(?CarbonImmutable $now = null): array
    {
        $now   = ($now ?? CarbonImmutable::now())->startOfDay();
        $since = $now->subDays(7);

        // Brand's global access scope applies — a team_member's digest covers only
        // the brands they are attached to.
        $brands   = Brand::query()->where('status', 'active')->get(['id', 'name', 'slug', 'niche']);
        $brandIds = $brands->pluck('id')->all();

        if ($brandIds === []) {
            return $this->empty($now, $since);
        }

        // ── New recommendations this week ──
        $newRecs = Recommendation::query()
            ->whereIn('brand_id', $brandIds)
            ->where('created_at', '>=', $since->toDateTimeString())
            ->with('brand:id,name,slug')
            ->orderByRaw("CASE kind WHEN 'pause' THEN 0 WHEN 'fix' THEN 1 ELSE 2 END")
            ->limit(10)
            ->get();

        $newRecCount = Recommendation::query()
            ->whereIn('brand_id', $brandIds)
            ->where('created_at', '>=', $since->toDateTimeString())
            ->count();

        // ── Open anomalies ──
        $anomalies = Anomaly::query()
            ->whereIn('brand_id', $brandIds)
            ->whereNull('resolved_at')
            ->with('brand:id,name,slug')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->limit(10)
            ->get();

        $openAnomalyCount = Anomaly::query()
            ->whereIn('brand_id', $brandIds)
            ->whereNull('resolved_at')
            ->count();

        // ── Track record: what Helm's advice did this week, wins AND losses ──
        $measuredThisWeek = Recommendation::query()
            ->whereIn('brand_id', $brandIds)
            ->whereNotNull('outcome')
            ->where('measured_at', '>=', $since->toDateTimeString())
            ->get(['outcome']);

        $overall = $this->trackRecord->compute($brandIds);

        // ── Competitor movement (Proxy — public Ad Library signals) ──
        $niches = $brands->pluck('niche')->filter()->unique()->values();
        $movement = [];
        foreach ($niches as $niche) {
            foreach ($this->marketAlerts->forPages((string) $niche) as $alert) {
                $movement[] = $alert + ['niche' => $niche];
            }
        }
        $movement = array_slice($movement, 0, 10);

        $sections = [
            'newRecommendations' => [
                'count' => $newRecCount,
                'rows'  => $newRecs->map(fn (Recommendation $r): array => [
                    'brand' => $r->brand?->name,
                    'slug'  => $r->brand?->slug,
                    'kind'  => $r->kind,
                    'title' => $r->title,
                ])->all(),
            ],
            'anomalies' => [
                'count' => $openAnomalyCount,
                'rows'  => $anomalies->map(fn (Anomaly $a): array => [
                    'brand'    => $a->brand?->name,
                    'slug'     => $a->brand?->slug,
                    'kind'     => $a->kind,
                    'severity' => $a->severity,
                    'date'     => $a->date->toDateString(),
                ])->all(),
            ],
            'trackRecord' => [
                // The uncomfortable numbers go in the digest too.
                'measuredThisWeek' => $measuredThisWeek->count(),
                'improvedThisWeek' => $measuredThisWeek->where('outcome', 'improved')->count(),
                'worsenedThisWeek' => $measuredThisWeek->where('outcome', 'worsened')->count(),
                'overallImprovedPct' => $overall['improvedPct'],   // null until measured — never 0%
                'overallAccepted'    => $overall['accepted'],
                'overallTotal'       => $overall['total'],
            ],
            'competitorMovement' => [
                'count' => count($movement),
                'rows'  => $movement,
                'label' => 'Proxy — public Ad Library signals',
            ],
        ];

        // Nothing actionable happened. Say so, and stop.
        $actionable = $newRecCount + $openAnomalyCount + count($movement) + $measuredThisWeek->count();

        return [
            'periodStart' => $since->toDateString(),
            'periodEnd'   => $now->toDateString(),
            'brands'      => count($brandIds),
            'empty'       => $actionable === 0,
            'emptyNote'   => 'Quiet week — nothing actionable. No new recommendations, no open anomalies, no competitor movement.',
            'sections'    => $sections,
        ];
    }

    /** @return array<string, mixed> */
    private function empty(CarbonImmutable $now, CarbonImmutable $since): array
    {
        return [
            'periodStart' => $since->toDateString(),
            'periodEnd'   => $now->toDateString(),
            'brands'      => 0,
            'empty'       => true,
            'emptyNote'   => 'No brands to report on.',
            'sections'    => [],
        ];
    }
}
