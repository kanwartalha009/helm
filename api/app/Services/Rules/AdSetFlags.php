<?php

declare(strict_types=1);

namespace App\Services\Rules;

use App\Models\AdSetDailyMetric;
use App\Models\Brand;
use Carbon\CarbonImmutable;

/**
 * Deterministic ad-set underperformer engine — spec §4 Phase 4
 * (docs/feature-specs/product-audit-adset-underperformers.md §3). Same shape as
 * ProductFlags: pure DB reads, NO HTTP, NO LLM. The ONE source of truth for an
 * ad set's flags — the campaign drawer and the store-audit cards both read it, so
 * an ad set is never "fine" on one surface and flagged on another.
 *
 * All money is normalised to USD via each row's stored fx snapshot (spec rule 7)
 * before any threshold compares — a €-brand and a $-brand grade on the same scale.
 * Every threshold comes ONLY from config/rules.adset. Missing ≠ zero: Meta-only
 * reach/frequency and Google-only impression-share stay null ("—"), never 0, and
 * no performance flag fires below `min_evidence_usd` spend (status-based
 * budget_starved / learning_limited are the sole exceptions, per §3).
 *
 * Rows are aggregated in PHP (not SQL) because two flags need per-day logic —
 * the ≥3-days evidence for a zero-purchase kill and Meta's "full-budget on ≥5 of
 * the last 7 days". An ad set × window is at most a few hundred rows.
 */
class AdSetFlags
{
    /**
     * Per-ad-set rows + flags for one campaign (the drawer endpoint).
     *
     * @return array{rows: list<array<string, mixed>>, asOf: ?string}
     */
    public function forCampaign(Brand $brand, string $platform, string $campaignId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = AdSetDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->where('platform', $platform)
            ->where('campaign_id', $campaignId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $sets = $this->evaluate($rows, $brand, $start, $end);

        // Sort by USD spend desc — the drawer leads with where the money is.
        usort($sets, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $asOf = null;
        foreach ($sets as $s) {
            if ($s['asOf'] !== null && ($asOf === null || $s['asOf'] > $asOf)) {
                $asOf = $s['asOf'];
            }
        }

        return ['rows' => array_values($sets), 'asOf' => $asOf];
    }

    /**
     * Every evaluated ad set across the brand (all platforms) for the window — a
     * flat list the audit controller rolls up by (platform, flag) the same way it
     * rolls up product flags. Each row carries platform + flags + spend + isActive,
     * so the caller can also derive the account-level fragmentation signal.
     *
     * @return list<array<string, mixed>>
     */
    public function forBrand(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = AdSetDailyMetric::query()
            ->where('brand_id', $brand->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        return $this->evaluate($rows, $brand, $start, $end);
    }

    /**
     * Group daily rows by ad_set_id, aggregate to USD, and evaluate flags.
     *
     * @param iterable<int, AdSetDailyMetric> $rows
     * @return list<array<string, mixed>>
     */
    private function evaluate(iterable $rows, Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cfg        = (array) config('rules.adset');
        $minEv      = (float) ($cfg['min_evidence_usd'] ?? 50);
        $killMult   = (float) ($cfg['kill_cpa_mult'] ?? 2.0);
        $freqWarn   = (float) ($cfg['frequency_warn'] ?? 4.0);
        $ctrFloor   = (float) ($cfg['ctr_floor_pct'] ?? 0.5);
        $budgetLost = (float) ($cfg['budget_lost_is'] ?? 0.10);

        $breakeven = $brand->breakevenRoas();
        $targetCpa = $brand->target_cpa !== null ? (float) $brand->target_cpa : null;

        // Last-7-days window (for Meta's full-budget signal) inside [start,end].
        $last7Start = $end->subDays(6);

        /** @var array<string, array<string, mixed>> $agg */
        $agg = [];
        foreach ($rows as $r) {
            $id = (string) $r->ad_set_id;
            if ($id === '') {
                continue;
            }
            $fx    = $r->fx_rate_to_usd !== null ? (float) $r->fx_rate_to_usd : 1.0;
            $spend = (float) $r->spend;
            $date  = $r->date instanceof CarbonImmutable ? $r->date->toDateString() : substr((string) $r->date, 0, 10);

            if (! isset($agg[$id])) {
                $agg[$id] = [
                    'adSetId' => $id, 'name' => (string) ($r->ad_set_name ?? ''), 'campaignId' => $r->campaign_id,
                    'platform' => (string) $r->platform, 'entityKind' => (string) ($r->entity_kind ?: 'ad_set'),
                    'spendUsd' => 0.0, 'valueUsd' => 0.0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0,
                    'reachSum' => 0, 'hasReach' => false, 'daysActive' => 0,
                    'blisSum' => 0.0, 'blisCount' => 0,
                    'latestDate' => null, 'status' => null, 'learning' => null, 'dailyBudget' => null, 'lifetimeBudget' => null,
                    'pulledAt' => null, 'fullBudgetDays' => 0,
                ];
            }
            $a = &$agg[$id];
            $a['spendUsd']    += $spend * $fx;
            $a['valueUsd']    += (float) $r->conversion_value * $fx;
            $a['impressions'] += (int) $r->impressions;
            $a['clicks']      += (int) $r->clicks;
            $a['conversions'] += (int) $r->conversions;
            if ($r->reach !== null) {
                $a['reachSum'] += (int) $r->reach;
                $a['hasReach']  = true;
            }
            if ($spend > 0) {
                $a['daysActive']++;
            }
            if ($r->search_budget_lost_is !== null) {
                $a['blisSum'] += (float) $r->search_budget_lost_is;
                $a['blisCount']++;
            }
            // Meta full-budget day: spend within 5% of that day's budget, inside
            // the last-7-days sub-window. Native units on both sides (same row).
            $budget = $r->daily_budget !== null ? (float) $r->daily_budget : null;
            if ($budget !== null && $budget > 0 && $spend >= 0.95 * $budget && $date >= $last7Start->toDateString()) {
                $a['fullBudgetDays']++;
            }
            if ($a['latestDate'] === null || $date > $a['latestDate']) {
                $a['latestDate']     = $date;
                $a['status']         = $r->status;
                $a['learning']       = $r->learning_status;
                $a['dailyBudget']    = $budget;
                $a['lifetimeBudget'] = $r->lifetime_budget !== null ? (float) $r->lifetime_budget : null;
            }
            $pulled = $r->pulled_at?->toIso8601String();
            if ($pulled !== null && ($a['pulledAt'] === null || $pulled > $a['pulledAt'])) {
                $a['pulledAt'] = $pulled;
            }
            unset($a);
        }

        $out = [];
        foreach ($agg as $a) {
            $out[] = $this->flagsForSet($a, $minEv, $killMult, $freqWarn, $ctrFloor, $budgetLost, $breakeven, $targetCpa);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a  one ad set's aggregate
     * @return array<string, mixed>    render-ready row + flags
     */
    private function flagsForSet(array $a, float $minEv, float $killMult, float $freqWarn, float $ctrFloor, float $budgetLost, ?float $breakeven, ?float $targetCpa): array
    {
        $platform = (string) $a['platform'];
        $spendUsd = round((float) $a['spendUsd'], 2);
        $valueUsd = (float) $a['valueUsd'];
        $imps     = (int) $a['impressions'];
        $conv     = (int) $a['conversions'];

        $roas = $spendUsd > 0 ? round($valueUsd / $spendUsd, 2) : null;
        $cpa  = $conv > 0 ? round($spendUsd / $conv, 2) : null;
        $ctr  = $imps > 0 ? round((int) $a['clicks'] / $imps * 100, 2) : null;
        $freq = ($platform === 'meta' && $a['hasReach'] && $a['reachSum'] > 0)
            ? round($imps / (int) $a['reachSum'], 2)
            : null;

        $evidence = $spendUsd >= $minEv;
        $flags    = [];

        // no_purchase_kill (critical) — zero purchases with real spend behind it.
        // Both paths still require ≥ min_evidence so the §3 evidence gate holds.
        if ($conv === 0 && $spendUsd >= $minEv) {
            $kill = $targetCpa !== null
                ? $spendUsd >= $killMult * $targetCpa
                : $a['daysActive'] >= 3; // ≥3 active days when no target CPA (spec §3)
            if ($kill) {
                $flags[] = $this->flag('no_purchase_kill', 'critical', 'Zero sales',
                    'Spent $' . $this->money($spendUsd) . ' with zero purchases — pause or fix targeting/creative.');
            }
        }

        // below_breakeven (warn; only when a margin is set so breakeven exists).
        if ($breakeven !== null && $evidence && $roas !== null && $roas < $breakeven) {
            $flags[] = $this->flag('below_breakeven', 'warn', 'Below breakeven',
                'ROAS ' . number_format($roas, 2) . '× is under this brand’s ' . number_format($breakeven, 2) . '× breakeven — losing money at this efficiency.');
        }

        // high_frequency (warn, Meta only).
        if ($platform === 'meta' && $freq !== null && $evidence && $freq >= $freqWarn) {
            $flags[] = $this->flag('high_frequency', 'warn', 'High frequency',
                'The same people are seeing this ad ' . number_format($freq, 1) . '× — fatigue territory; refresh creative or widen the audience. (Blended over the window; true deduped frequency needs a windowed pull.)');
        }

        // low_ctr (info) — needs a real impression base; 1,000 floor [SOURCED spec §3].
        if ($evidence && $ctr !== null && $imps >= 1000 && $ctr < $ctrFloor) {
            $flags[] = $this->flag('low_ctr', 'info', 'Low CTR',
                'CTR ' . number_format($ctr, 2) . '% is below the ' . number_format($ctrFloor, 1) . '% floor — creative or targeting mismatch.');
        }

        // learning_limited (warn, Meta) — status-based, exempt from the evidence gate.
        if ($platform === 'meta' && $this->learningLimited($a['learning'])) {
            $flags[] = $this->flag('learning_limited', 'warn', 'Stuck in learning',
                'Meta says this ad set can’t gather ~50 optimization events a week — consolidate budget or loosen targeting (per Meta guidance).');
        }

        // budget_starved (info) — status-based, exempt from the evidence gate.
        $bs = $this->budgetStarved($a, $platform, $budgetLost);
        if ($bs !== null) {
            $flags[] = $bs;
        }

        return [
            'adSetId'        => $a['adSetId'],
            'name'           => $a['name'],
            'campaignId'     => $a['campaignId'],
            'platform'       => $platform,
            'entityKind'     => $a['entityKind'],
            'status'         => $a['status'],
            'learningStatus' => $a['learning'],
            'dailyBudget'    => $a['dailyBudget'],
            'spend'          => $spendUsd,
            'roas'           => $roas,
            'cpa'            => $cpa,
            'ctr'            => $ctr,
            'frequency'      => $freq,
            'conversions'    => $conv,
            'flags'          => $flags,
            'asOf'           => $a['pulledAt'] ?? $a['latestDate'],
            'isActive'       => $this->isActive($a['status'], $spendUsd),
        ];
    }

    /**
     * Budget-starved, platform-specific (spec §3). Google reports the metric
     * directly; TikTok surfaces it in status; Meta is inferred from full-budget
     * days. Returns the flag array or null.
     *
     * @param array<string, mixed> $a
     * @return array{key:string,severity:string,label:string,detail:string}|null
     */
    private function budgetStarved(array $a, string $platform, float $budgetLost): ?array
    {
        if ($platform === 'google') {
            if ((int) $a['blisCount'] === 0) {
                return null;
            }
            $avg = (float) $a['blisSum'] / (int) $a['blisCount'];
            if ($avg >= $budgetLost) {
                return $this->flag('budget_starved', 'info', 'Budget-starved',
                    'Losing ' . number_format($avg * 100, 0) . '% of possible impressions to budget — raise the budget to capture them.');
            }

            return null;
        }

        if ($platform === 'tiktok') {
            $status = strtoupper((string) ($a['status'] ?? ''));
            if ($status !== '' && str_contains($status, 'BUDGET')) {
                return $this->flag('budget_starved', 'info', 'Budget-starved',
                    'TikTok reports this ad group is limited by budget — raise it to test scale.');
            }

            return null;
        }

        // Meta: spent its full budget on ≥5 of the last 7 days (HELM DEFAULT).
        if ($platform === 'meta' && (int) $a['fullBudgetDays'] >= 5) {
            return $this->flag('budget_starved', 'info', 'Budget-starved',
                'Spent its full daily budget on ' . (int) $a['fullBudgetDays'] . ' of the last 7 days — it may be capped; raise the budget to test scale.');
        }

        return null;
    }

    private function learningLimited(mixed $learning): bool
    {
        if ($learning === null) {
            return false;
        }

        // Meta's real token is LEARNING_LIMITED; the spec calls it "FAIL".
        return in_array(strtoupper((string) $learning), ['LEARNING_LIMITED', 'FAIL'], true);
    }

    private function isActive(mixed $status, float $spendUsd): bool
    {
        if ($status === null) {
            return $spendUsd > 0; // no status snapshot → treat as active if it spent
        }
        $s = strtoupper((string) $status);

        return ! str_contains($s, 'PAUSE')
            && ! str_contains($s, 'ARCHIV')
            && ! str_contains($s, 'REMOV')
            && ! str_contains($s, 'DISABLE')
            && ! str_contains($s, 'DELET');
    }

    private function money(float $v): string
    {
        return number_format($v, $v >= 100 ? 0 : 2);
    }

    /** @return array{key: string, severity: string, label: string, detail: string} */
    private function flag(string $key, string $severity, string $label, string $detail): array
    {
        return ['key' => $key, 'severity' => $severity, 'label' => $label, 'detail' => $detail];
    }
}
