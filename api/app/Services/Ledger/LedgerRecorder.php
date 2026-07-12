<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\Anomaly;
use App\Models\Brand;
use App\Reports\Support\AdAudit;
use App\Services\Rules\AdSetFlags;
use App\Services\Rules\ProductFlags;
use App\Services\Rules\SeasonalStale;
use Carbon\CarbonImmutable;

/**
 * The SILENT writers (GO-2.5). Runs the engines Helm already has — AdAudit, AdSetFlags,
 * ProductFlags, and the anomaly feed — and logs whatever they assert into the ledger.
 *
 * Deliberately a scheduled recorder rather than a hook inside the engines themselves.
 * AdAudit runs on every page view; writing a ledger row from there would log the same
 * advice dozens of times a day and make the acceptance rate meaningless. Advice is
 * recorded once, when the nightly scan says it.
 *
 * Nothing renders these rows yet. That is the point: the track record can only be
 * computed from history, so the history has to start accruing BEFORE the UI that needs
 * it exists. Every night this runs, the moat gets one day deeper.
 */
class LedgerRecorder
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /** Map an engine's vocabulary onto the ledger's. */
    private const AD_AUDIT_KIND = ['stop' => 'pause', 'fix' => 'fix', 'scale' => 'scale'];

    private const FLAG_KIND = [
        'no_purchase_kill'  => 'pause',
        'below_breakeven'   => 'fix',
        'high_frequency'    => 'fix',
        'low_ctr'           => 'fix',
        'learning_limited'  => 'fix',
        'budget_starved'    => 'budget_shift',
        'losing_on_ads'     => 'fix',
        'dead_stock'        => 'investigate',
        'stockout_on_ads'   => 'fix',
        'zero_delivery'     => 'investigate',
    ];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly AdAudit $ads,
        private readonly AdSetFlags $adSetFlags,
        private readonly ProductFlags $productFlags,
        private readonly SeasonalStale $seasonalStale,
    ) {}

    /**
     * Record everything the engines currently assert about one brand.
     *
     * @return int rows newly written (an already-open recommendation writes nothing)
     */
    public function recordForBrand(Brand $brand, ?CarbonImmutable $asOf = null): int
    {
        $tz  = $brand->timezone ?: 'UTC';
        $end = ($asOf ?? CarbonImmutable::now($tz)->subDay())->startOfDay();
        $start = $end->subDays(29);
        $priorEnd   = $start->subDay();
        $priorStart = $priorEnd->subDays(29);

        $before = $this->openCount($brand);

        $this->fromAdAudit($brand, $start, $end, $priorStart, $priorEnd);
        $this->fromAdSetFlags($brand, $start, $end);
        $this->fromProductFlags($brand, $start, $end);
        $this->fromAnomalies($brand);
        $this->fromSeasonalStale($brand, $end);

        return $this->openCount($brand) - $before;
    }

    /** Campaign-level verdicts (stop / fix / scale) — the audit's own action list. */
    private function fromAdAudit(Brand $brand, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $priorStart, CarbonImmutable $priorEnd): void
    {
        $connected = $brand->connections()->where('status', 'active')->pluck('platform')->all();

        foreach (self::AD_PLATFORMS as $platform) {
            if (! in_array($platform, $connected, true)) {
                continue;
            }

            $audit = $this->ads->forPlatform(
                $brand->id, $platform,
                $start->toDateString(), $end->toDateString(),
                $priorStart->toDateString(), $priorEnd->toDateString(),
                usd: false,
            );
            if ($audit === null) {
                continue;
            }

            foreach (($audit['actions'] ?? []) as $action) {
                $kind = self::AD_AUDIT_KIND[$action['kind'] ?? ''] ?? null;
                if ($kind === null) {
                    continue;
                }

                $this->ledger->record(
                    $brand,
                    source: 'ad_audit',
                    kind: $kind,
                    subjectType: 'brand',           // the audit's actions are platform-level rollups
                    subjectId: $platform,
                    title: ucfirst($platform) . ': ' . (string) $action['title'],
                    evidence: [
                        'rule'     => 'ad_audit.' . ($action['kind'] ?? ''),
                        'platform' => $platform,
                        'body'     => (string) ($action['body'] ?? ''),
                        'window'   => $start->toDateString() . '..' . $end->toDateString(),
                        'waste'    => $audit['waste'] ?? null,
                    ],
                    confidence: (string) ($action['confidence'] ?? 'solid'),
                    outcomeMetric: $kind === 'pause' ? 'spend_waste' : 'roas',
                );
            }
        }
    }

    /** Ad-set flags — the middle layer (D-021). */
    private function fromAdSetFlags(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): void
    {
        foreach ($this->adSetFlags->forBrand($brand, $start, $end) as $set) {
            foreach (($set['flags'] ?? []) as $flag) {
                $kind = self::FLAG_KIND[$flag['key'] ?? ''] ?? null;
                if ($kind === null) {
                    continue;
                }

                $this->ledger->record(
                    $brand,
                    source: 'adset_flags',
                    kind: $kind,
                    subjectType: 'adset',
                    subjectId: (string) $set['adSetId'],
                    title: (string) ($flag['label'] ?? $flag['key']) . ' — ' . ((string) $set['name'] ?: (string) $set['adSetId']),
                    evidence: [
                        'rule'     => 'adset_flags.' . $flag['key'],
                        'platform' => $set['platform'] ?? null,
                        'adSetId'  => $set['adSetId'],
                        'detail'   => (string) ($flag['detail'] ?? ''),
                        'severity' => (string) ($flag['severity'] ?? ''),
                        'spendUsd' => $set['spend'] ?? null,
                        'window'   => $start->toDateString() . '..' . $end->toDateString(),
                    ],
                    // The engine only flags performance past its own $50 evidence floor,
                    // so a flag on thin spend is an early signal, not a solid verdict.
                    confidence: ((float) ($set['spend'] ?? 0)) >= AdAudit::SOLID_SPEND ? 'solid' : 'early',
                    outcomeMetric: $kind === 'pause' ? 'spend_waste' : 'roas',
                    baselineValue: isset($set['roas']) ? (float) $set['roas'] : null,
                );
            }
        }
    }

    /** Product flags — stock and margin problems worth acting on. */
    private function fromProductFlags(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): void
    {
        foreach ($this->productFlags->forBrand($brand->id, $start, $end) as $title => $p) {
            foreach (($p['flags'] ?? []) as $flag) {
                $kind = self::FLAG_KIND[$flag['key'] ?? ''] ?? 'investigate';

                $this->ledger->record(
                    $brand,
                    source: 'product_flags',
                    kind: $kind,
                    subjectType: 'product',
                    subjectId: mb_substr((string) $title, 0, 191),
                    title: (string) ($flag['label'] ?? $flag['key']) . ' — ' . (string) $title,
                    evidence: [
                        'rule'     => 'product_flags.' . $flag['key'],
                        'product'  => (string) $title,
                        'detail'   => (string) ($flag['detail'] ?? ''),
                        'severity' => (string) ($flag['severity'] ?? ''),
                        'window'   => $start->toDateString() . '..' . $end->toDateString(),
                    ],
                    confidence: 'solid',   // product flags are stock/margin facts, not thin-spend inferences
                    outcomeMetric: 'revenue',
                );
            }
        }
    }

    /** Open anomalies become "investigate" recommendations, carrying their evidence. */
    private function fromAnomalies(Brand $brand): void
    {
        $open = Anomaly::query()
            ->where('brand_id', $brand->id)
            ->whereNull('resolved_at')
            ->get();

        foreach ($open as $a) {
            $kind = self::FLAG_KIND[$a->kind] ?? 'investigate';

            $this->ledger->record(
                $brand,
                source: 'anomaly',
                kind: $kind,
                subjectType: $a->subject !== '' ? 'product' : 'brand',
                subjectId: (string) ($a->subject !== '' ? $a->subject : $a->kind),
                title: str_replace('_', ' ', ucfirst($a->kind)) . ($a->subject !== '' ? ' — ' . $a->subject : ''),
                // The anomaly's evidence IS the recommendation's evidence — same numbers,
                // same median, same threshold. Nothing is re-derived or re-worded.
                evidence: ['rule' => 'anomaly.' . $a->kind, 'anomalyId' => $a->id, 'date' => $a->date->toDateString()]
                    + (array) $a->evidence,
                confidence: 'solid',
                outcomeMetric: in_array($a->kind, ['roas_drop', 'cpa_spike', 'cpm_spike'], true) ? 'roas' : 'revenue',
            );
        }
    }

    /**
     * Seasonal-stale creatives (GO-3.1) — an ad still spending on a dead hook.
     *
     * The trigger is the RULE (keyword match + season ended + grace), never a model.
     * The evidence carries the matched terms and the window, so the operator can check
     * the claim in two seconds rather than take it on faith.
     */
    private function fromSeasonalStale(Brand $brand, CarbonImmutable $asOf): void
    {
        foreach ($this->seasonalStale->forBrand($brand, $asOf) as $s) {
            $this->ledger->record(
                $brand,
                source: 'seasonal_stale',
                kind: 'creative_refresh',
                subjectType: 'ad',
                subjectId: (string) $s['adId'],
                title: 'Retire ' . $s['seasonLabel'] . ' creative — still live ' . $s['daysStale'] . ' days after the season ended',
                evidence: [
                    'rule'         => 'seasonal_stale.' . $s['season'],
                    'season'       => $s['season'],
                    'seasonLabel'  => $s['seasonLabel'],
                    'matchedTerms' => $s['matchedTerms'],
                    'seasonEnded'  => $s['seasonEnded'],
                    'staleSince'   => $s['staleSince'],
                    'daysStale'    => $s['daysStale'],
                    'adName'       => $s['adName'],
                    'platform'     => $s['platform'],
                    'spendLast7d'  => $s['spend'],
                    'spendLast7dUsd' => $s['spendUsd'],
                    'trigger'      => 'keyword+date rule (no model involved)',
                ],
                confidence: 'solid',   // a date and a matched word are facts, not inferences
                outcomeMetric: 'spend_waste',
                baselineValue: (float) $s['spendUsd'],
            );
        }
    }

    private function openCount(Brand $brand): int
    {
        return \App\Models\Recommendation::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'open')
            ->count();
    }
}
