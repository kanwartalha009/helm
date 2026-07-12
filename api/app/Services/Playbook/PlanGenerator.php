<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use App\Models\Brand;
use App\Models\BrandTarget;
use App\Models\DailyMetric;
use App\Models\MarketMoment;
use App\Services\AdsLibrary\GapMap;
use App\Services\Rules\DataQuality;
use Carbon\CarbonImmutable;

/**
 * The seasonal plan generator (GO-4.3, master plan §7.3) — the crown jewel.
 *
 * "Here is your Black Friday plan for FR, grounded in YOUR numbers, with every figure
 * traceable to a table row or a cited constant." Nothing on the market does this
 * (assessment §2: total whitespace, rated 10/10).
 *
 * ══ EVERY NUMBER IS RULE-ASSEMBLED. THE LLM NEVER PRODUCES ONE. ══
 * This class contains no model call and must never contain one. It reads:
 *   - the brand's OWN history for the same moment last year      → Verified
 *   - the sourced playbook physics (GO-4.2)                      → Source (cited)
 *   - competitor presence in the market (Ads Library)            → Proxy
 *   - a forecast, where one is honestly available                → Modeled
 * and every entry it emits carries its `basis` so a client can ask "where did this come
 * from?" and get an answer. PlanNarrator turns these blocks into prose afterwards — it
 * rewrites, it does not compute.
 *
 * ══ TWO REFUSALS ══
 * 1. **Data-quality gate.** Below the threshold (GO-1.3) the generator refuses outright.
 *    A strategist confidently planning a client's biggest quarter on holey data is the
 *    generic-advice failure mode that killed trust in every incumbent.
 * 2. **No margin, no budget block.** CAC ceilings are derived from gross margin. Without
 *    it we cannot say what an acquisition may cost, so the budget block says "set your
 *    margin" instead of inventing one. A guessed CAC ceiling is how an agency talks a
 *    client into spending money they don't make back.
 */
class PlanGenerator
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    public function __construct(
        private readonly PlaybookPhysics $physics,
        private readonly DataQuality $quality,
        private readonly GapMap $gapMap,
    ) {}

    /**
     * @return array<string, mixed> status: 'ok' | 'insufficient_quality' | 'no_moment'
     */
    public function generate(Brand $brand, string $momentKey, string $market, ?int $year = null): array
    {
        $year   = $year ?? CarbonImmutable::now()->year;
        $market = strtoupper($market);

        // ── Refusal 1: the data-quality gate ──
        $q = $this->quality->forBrand($brand);
        if (! $q['meetsGate']) {
            return [
                'status'    => 'insufficient_quality',
                'score'     => $q['score'],
                'threshold' => $q['threshold'],
                'reason'    => "This brand's data quality is {$q['score']}/100, below the {$q['threshold']} "
                    . 'needed to plan on. Helm will not build a seasonal plan on incomplete data — '
                    . 'the plan would look just as confident as a good one. Close the gaps on the brand page first.',
            ];
        }

        $moment = MarketMoment::query()
            ->where('market', $market)->where('moment_key', $momentKey)->where('year', $year)->first();

        if ($moment === null) {
            return [
                'status' => 'no_moment',
                'reason' => "No '{$momentKey}' moment seeded for {$market} in {$year}. Run `php artisan calendar:seed {$year}`.",
            ];
        }

        $start = CarbonImmutable::parse($moment->starts_on->toDateString());
        $end   = CarbonImmutable::parse($moment->ends_on->toDateString());

        $history = $this->lastYear($brand, $start, $end);

        $blocks = [
            'timeline'    => $this->timelineBlock($moment, $start, $end),
            'budget'      => $this->budgetBlock($brand, $history, $start, $end),
            'channel'     => $this->channelBlock($brand, $history, $market),
            'creative'    => $this->creativeBlock(),
            'measurement' => $this->measurementBlock($brand, $history, $start),
        ];

        return [
            'status'  => 'ok',
            'moment'  => [
                'key'    => $moment->moment_key,
                'label'  => $moment->label,
                'market' => $market,
                'kind'   => $moment->kind,          // legal_sale windows are not optional
                'starts' => $start->toDateString(),
                'ends'   => $end->toDateString(),
                'source' => $moment->source,
            ],
            'title'   => $moment->label . ' — ' . $market . ' ' . $year,
            'year'    => $year,
            'blocks'  => $blocks,
            'qualityScore' => $q['score'],
        ];
    }

    /**
     * The same moment, one year earlier — the brand's OWN numbers. Verified.
     *
     * @return array<string, mixed>
     */
    private function lastYear(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ly = [$start->subYear()->toDateString(), $end->subYear()->toDateString()];

        $store = DailyMetric::query()
            ->where('brand_id', $brand->id)->where('platform', 'shopify')->where('is_complete', true)
            ->whereBetween('date', $ly)
            ->selectRaw('COALESCE(SUM(COALESCE(total_sales,0) + COALESCE(refunds_amount,0)), 0) AS revenue,
                         COALESCE(SUM(COALESCE(orders,0)), 0) AS orders,
                         COUNT(DISTINCT date) AS days')
            ->first();

        $byPlatform = [];
        $totalSpend = 0.0;
        foreach (self::AD_PLATFORMS as $p) {
            $r = DailyMetric::query()
                ->where('brand_id', $brand->id)->where('platform', $p)
                ->whereBetween('date', $ly)
                ->selectRaw('COALESCE(SUM(COALESCE(spend,0)), 0) AS spend,
                             COALESCE(SUM(COALESCE(impressions,0)), 0) AS impressions,
                             COALESCE(SUM(COALESCE(conversions,0)), 0) AS conversions')
                ->first();
            $spend = (float) ($r->spend ?? 0);
            if ($spend <= 0.0) {
                continue;   // absent, not zero
            }
            $impr = (int) ($r->impressions ?? 0);
            $byPlatform[$p] = [
                'spend' => $spend,
                'cpm'   => $impr > 0 ? $spend / $impr * 1000 : null,
                'cpa'   => ((int) $r->conversions) > 0 ? $spend / (int) $r->conversions : null,
            ];
            $totalSpend += $spend;
        }

        // Baseline daily spend from the 30 days BEFORE the event last year — the ramp is
        // measured against normal trading, not against the event itself.
        $baseFrom = $start->subYear()->subDays(30)->toDateString();
        $baseTo   = $start->subYear()->subDay()->toDateString();
        $base = DailyMetric::query()
            ->where('brand_id', $brand->id)->whereIn('platform', self::AD_PLATFORMS)
            ->whereBetween('date', [$baseFrom, $baseTo])
            ->selectRaw('COALESCE(SUM(COALESCE(spend,0)), 0) AS spend, COUNT(DISTINCT date) AS days')
            ->first();
        $baseDays = (int) ($base->days ?? 0);

        $revenue = (float) ($store->revenue ?? 0);
        $orders  = (int) ($store->orders ?? 0);

        return [
            'hasHistory'  => ((int) ($store->days ?? 0)) > 0,
            'revenue'     => round($revenue, 2),
            'orders'      => $orders,
            'aov'         => $orders > 0 ? round($revenue / $orders, 2) : null,   // null, never 0
            'spend'       => round($totalSpend, 2),
            'roas'        => $totalSpend > 0.0 ? round($revenue / $totalSpend, 2) : null,
            'byPlatform'  => $byPlatform,
            // Baseline for the ramp. Null when we have no pre-event spend to compare to.
            'baselineDailySpend' => $baseDays > 0 ? round((float) $base->spend / $baseDays, 2) : null,
            'window'      => $ly,
        ];
    }

    /** Dated milestones, straight from the sourced physics. */
    private function timelineBlock(MarketMoment $moment, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $preheat  = $this->physics->cite('preheat_weeks_start');
        $locked   = $this->physics->cite('preheat_weeks_creative_locked');
        $build    = $this->physics->cite('build_lead_hours');
        $judge    = $this->physics->cite('judgment_days_min');
        $post     = $this->physics->cite('post_event_phase_days');

        $entries = [
            $this->e('Pre-heat starts', $start->subWeeks((int) $preheat['value'])->toDateString(), 'Source', $preheat['source'], $preheat['label']),
            $this->e('Creative locked', $start->subWeeks((int) $locked['value'])->toDateString(), 'Source', $locked['source'], $locked['label']),
            $this->e('Campaigns built', $start->subHours((int) $build['value'])->toDateString(), 'Source', $build['source'], $build['label']),
            $this->e('Event starts', $start->toDateString(), 'Verified', $moment->source, $moment->label),
            $this->e('Earliest judgment', $start->addDays((int) $judge['value'])->toDateString(), 'Source', $judge['source'], $judge['label']),
            $this->e('Event ends', $end->toDateString(), 'Verified', $moment->source, $moment->label),
            $this->e('Post-event phase ends', $end->addDays((int) $post['value'])->toDateString(), 'Source', $post['source'], $post['label']),
        ];

        $note = $moment->kind === 'legal_sale'
            ? 'This window is FIXED BY LAW in this market — the dates are not a choice.'
            : null;

        return ['entries' => $entries, 'note' => $note];
    }

    /**
     * Budget + CAC ceilings. REFUSES without a gross margin.
     *
     * The CAC ceiling is what an order may cost before it stops being profitable:
     *     ceiling = AOV × gross margin
     * The scenarios then ask: if CPMs rise X%, and conversion rate holds, does last year's
     * CPA breach that ceiling? That assumption (CVR held constant) is STATED, because a
     * scenario whose assumptions are hidden is a forecast pretending to be arithmetic.
     */
    private function budgetBlock(Brand $brand, array $history, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $margin = $brand->gross_margin_pct !== null ? (float) $brand->gross_margin_pct : null;

        if ($margin === null || $margin <= 0.0) {
            // Refusal 2. A guessed CAC ceiling is how an agency talks a client into
            // spending money they never make back.
            return [
                'blocked' => true,
                'reason'  => 'Set this brand’s gross margin % in Settings. CAC ceilings are derived from margin — '
                    . 'without it Helm cannot say what an order is allowed to cost, and it will not guess.',
                'entries' => [],
            ];
        }

        $ramp   = $this->physics->cite('event_budget_ramp');
        $cpms   = $this->physics->cite('cpm_spike_scenarios');
        $days   = (int) $start->diffInDays($end) + 1;
        $base   = $history['baselineDailySpend'];
        $aov    = $history['aov'];

        $entries = [];

        // The ramp — against last year's PRE-event baseline, not against the event itself.
        if ($base !== null) {
            [$lo, $hi] = $ramp['value'];
            $entries[] = $this->e(
                'Suggested event budget',
                $this->money($base * (float) $lo * $days) . ' – ' . $this->money($base * (float) $hi * $days),
                'Modeled',
                $ramp['source'],
                $this->money($base) . '/day baseline (30 days before last year’s event) × ' . $lo . '–' . $hi . '× × ' . $days . ' days',
            );
        } else {
            $entries[] = $this->e('Suggested event budget', '—', 'Modeled', $ramp['source'],
                'No pre-event spend last year to measure a baseline against — set the budget from the plan, not from history.');
        }

        // Last year's actuals — the honest anchor.
        if ($history['hasHistory']) {
            $entries[] = $this->e('Last year, same window — revenue', $this->money($history['revenue']), 'Verified', 'Shopify (D-005 basis)', null);
            $entries[] = $this->e('Last year, same window — ad spend', $this->money($history['spend']), 'Verified', 'Ad platforms', null);
            if ($history['roas'] !== null) {
                $entries[] = $this->e('Last year, same window — MER', $history['roas'] . '×', 'Verified', 'Store revenue ÷ total ad spend', null);
            }
        } else {
            $entries[] = $this->e('Last year, same window', 'no history', 'Verified', 'Shopify',
                'This brand has no data for this window last year — the plan cannot be anchored to it.');
        }

        // CAC ceiling + CPM stress scenarios.
        if ($aov !== null) {
            $ceiling = round($aov * $margin / 100, 2);
            $entries[] = $this->e('CAC ceiling', $this->money($ceiling), 'Verified',
                'AOV × gross margin (' . $margin . '%)',
                'What one order may cost to acquire before it stops being profitable. AOV ' . $this->money($aov) . ' last year.');

            $lastCpa = $this->blendedCpa($history);
            foreach ((array) $cpms['value'] as $pct) {
                $projected = $lastCpa !== null ? round($lastCpa * (1 + (int) $pct / 100), 2) : null;
                $verdict = $projected === null ? 'no CPA history'
                    : ($projected <= $ceiling ? 'within ceiling' : 'BREACHES ceiling');

                $entries[] = $this->e(
                    'CPM +' . $pct . '% → projected CAC',
                    $projected === null ? '—' : $this->money($projected) . ' (' . $verdict . ')',
                    'Modeled',
                    $cpms['source'],
                    'Assumes conversion rate holds. Observed BFCM CPMs run +50–150%, so this is a stress test of your '
                        . 'margin, not a CPM forecast — if the ceiling breaks at +20% it will shatter at +100%.',
                );
            }
        } else {
            $entries[] = $this->e('CAC ceiling', '—', 'Verified', 'AOV × gross margin',
                'No orders last year in this window, so there is no AOV to derive a ceiling from.');
        }

        return ['blocked' => false, 'entries' => $entries];
    }

    /** Where the money went last year, plus who else is in this market now (Proxy). */
    private function channelBlock(Brand $brand, array $history, string $market): array
    {
        $entries = [];
        $total   = array_sum(array_column($history['byPlatform'], 'spend'));

        foreach ($history['byPlatform'] as $platform => $p) {
            $share = $total > 0.0 ? round($p['spend'] / $total * 100, 1) : null;
            $entries[] = $this->e(
                ucfirst($platform),
                $this->money($p['spend']) . ($share !== null ? " ({$share}% of spend)" : ''),
                'Verified',
                'Ad platform, same window last year',
                $p['cpa'] !== null ? 'CPA ' . $this->money($p['cpa']) : null,
            );
        }

        if ($entries === []) {
            $entries[] = $this->e('Channel split', '—', 'Verified', 'Ad platforms',
                'No ad spend in this window last year — there is no historical split to plan from.');
        }

        // Competitor presence — PROXY. Presence only; the EU Ad Library publishes no
        // competitor spend and Helm will not estimate one.
        $gap = $this->gapMap->forBrand($brand);
        if (($gap['status'] ?? '') === 'ok') {
            foreach ($gap['rows'] as $row) {
                if ($row['market'] !== $market) {
                    continue;
                }
                $entries[] = $this->e(
                    'Competitors in ' . $market,
                    $row['competitorPages'] . ' advertiser(s), ' . $row['competitorConcepts'] . ' live concept(s)',
                    'Proxy',
                    'Meta Ad Library — public presence only, no competitor spend exists',
                    'A gap is a question, not proof: they chose to be here, which is not the same as it paying off.',
                );
            }
        }

        return ['entries' => $entries];
    }

    /** How much creative, and the hooks that already worked for us. */
    private function creativeBlock(): array
    {
        $min = $this->physics->cite('min_event_creatives');

        return [
            'entries' => [
                $this->e('Event-ready creatives', '≥ ' . $min['value'], 'Source', $min['source'], $min['label']),
                $this->e(
                    'Proven hooks',
                    'from your tagged winners',
                    'Verified',
                    'Ads Library — tag benchmarks over your own creatives ($50 evidence floor)',
                    'Use the Boards tab: hooks with ≥3 tagged creatives show a real median ROAS from your own accounts.',
                ),
            ],
        ];
    }

    /** What "working" will mean, decided BEFORE the money is spent. */
    private function measurementBlock(Brand $brand, array $history, CarbonImmutable $start): array
    {
        $judge = $this->physics->cite('judgment_days_min');
        $email = $this->physics->cite('email_share_of_event_revenue');

        $month  = $start->format('Y-m');
        $target = BrandTarget::query()->where('brand_id', $brand->id)->where('month', $month)->first();

        $entries = [
            $this->e('Judge no earlier than', $start->addDays((int) $judge['value'])->toDateString(), 'Source', $judge['source'],
                'Killing a campaign on day 2 of a peak event is judging noise, not performance.'),
            $this->e('Truth metric', 'MER (store revenue ÷ total ad spend)', 'Verified', 'GO-1.4 truth spine',
                'Platform-reported ROAS over-credits itself during peak. Judge on MER.'),
        ];

        if ($history['roas'] !== null) {
            $entries[] = $this->e('MER to beat', $history['roas'] . '×', 'Verified', 'Same window last year', null);
        }

        if ($target?->mer_target !== null) {
            $entries[] = $this->e('MER target', $target->mer_target . '×', 'Verified', 'Brand target for ' . $month, null);
        }

        $entries[] = $this->e('Email contribution', $email['value'][0] . '–' . $email['value'][1] . '% of event revenue',
            'Source', $email['source'], 'Typical share — size the Klaviyo plan against it, do not treat it as a target.');

        return ['entries' => $entries];
    }

    /** Blended CPA across platforms last year. Null when there were no conversions. */
    private function blendedCpa(array $history): ?float
    {
        $spend = 0.0;
        $convs = 0;
        foreach ($history['byPlatform'] as $p) {
            $spend += $p['spend'];
            if ($p['cpa'] !== null && $p['cpa'] > 0) {
                $convs += (int) round($p['spend'] / $p['cpa']);
            }
        }

        return $convs > 0 ? round($spend / $convs, 2) : null;
    }

    /**
     * One plan entry. `basis` is mandatory: Verified | Proxy | Modeled | Source.
     * A number without a basis cannot go in a client plan.
     *
     * @return array<string, mixed>
     */
    private function e(string $label, string $value, string $basis, string $source, ?string $detail): array
    {
        return [
            'label'  => $label,
            'value'  => $value,
            'basis'  => $basis,
            'source' => $source,
            'detail' => $detail,
        ];
    }

    private function money(float $v): string
    {
        return number_format($v, 2);
    }
}
