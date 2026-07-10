<?php

declare(strict_types=1);

namespace App\Reports\Support;

use App\Models\AdCampaignDailyMetric;
use Carbon\CarbonImmutable;

/**
 * Turns ad_campaign_daily_metrics (slice 2.2 / 2.4) into a render-ready audit
 * for one ad platform — account KPIs (spend, purchases, ROAS, CTR, CPM) with
 * deltas, a per-campaign kill-list with a rules-based verdict + action, the
 * wasted spend on losing campaigns, and a prioritised action list.
 *
 * Every verdict and number is rules-driven from real comparison data — the LLM
 * layer later writes the prose "honest read" around these, never the figures.
 * Returns null when the brand has no campaign rows for the platform/window, so
 * the report omits the section until the campaign backfill has run.
 */
final class AdAudit
{
    // ROAS bands (computed in USD so the ratio is currency-correct). Defaults —
    // tune once Bosco confirms his banding (open item #16).
    private const DEAD_ROAS = 1.0;   // < 1× → losing money on the campaign
    private const WEAK_ROAS = 1.8;   // 1–1.8× → underperforming
    private const WIN_ROAS  = 3.0;   // ≥ 3× → scale
    private const MIN_SPEND = 50.0;  // USD floor below which a campaign isn't worth a verdict
    private const SCALING   = 20.0;  // spend Δ% above which a weak campaign is "scaling the loss"

    // Evidence floor for a CONFIDENT verdict (3 × MIN_SPEND). Below it a verdict
    // still renders but is tagged 'early' — practitioners attach minimum-spend
    // guards before acting on verdicts (Bïrch rule templates use spend > $50 as
    // the validity floor; staged kill frameworks want ~2–3× target CPA spent
    // before a confident kill — https://bir.ch/facebook-automated-rules,
    // https://admanage.ai/blog/when-to-kill-a-facebook-ad). Additive only: no
    // existing verdict threshold changes.
    public const SOLID_SPEND = 150.0;

    public function forPlatform(
        int $brandId,
        string $platform,
        string $start,
        string $end,
        ?string $cStart,
        ?string $cEnd,
        bool $usd,
        int $limit = 12,
    ): ?array {
        $cur = $this->aggregate($brandId, $platform, $start, $end);
        if ($cur === []) {
            return null;
        }

        // How much evidence the window holds — short windows carry less spend,
        // so their verdicts are tagged 'early' more often (see SOLID_SPEND).
        $windowDays = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
        $prev = ($cStart !== null && $cEnd !== null) ? $this->aggregate($brandId, $platform, $cStart, $cEnd) : [];

        $disp = static fn (array $r): float => $usd ? $r['spend_usd'] : $r['spend_native'];

        // Account roll-up. Money KPIs (spend, CPM) follow the report's display
        // currency so they agree with the waste figure and the campaign rows;
        // ROAS stays a USD ratio so it's currency-correct in either mode.
        $acc  = $this->totals($cur, $usd);
        $accP = $prev !== [] ? $this->totals($prev, $usd) : null;

        // Per-campaign verdicts, ranked by spend (where the money goes).
        usort($cur, static fn (array $a, array $b): int => $b['spend_usd'] <=> $a['spend_usd']);

        $campaigns = [];
        $wasteUsd  = 0.0;
        $wasteCount = 0;
        foreach ($cur as $c) {
            $roas    = $c['spend_usd'] > 0 ? $c['value_usd'] / $c['spend_usd'] : null;
            $prevC   = $prev[$c['campaign_id']] ?? null;
            $prevRoas = $prevC && $prevC['spend_usd'] > 0 ? $prevC['value_usd'] / $prevC['spend_usd'] : null;
            $spendDelta = $this->pct($c['spend_usd'], $prevC['spend_usd'] ?? null);

            $verdict = $this->verdict($roas, $c['spend_usd'], $spendDelta);
            if ($verdict === 'dead') {
                $wasteUsd += $c['spend_usd'];
                $wasteCount++;
            }

            $campaigns[] = [
                'id'         => $c['campaign_id'],
                'name'       => $c['name'] !== '' ? $c['name'] : $c['campaign_id'],
                'spend'      => round($disp($c), 2),
                'roas'       => $roas !== null ? round($roas, 2) : null,
                'conversions' => $c['conversions'],
                'prevRoas'   => $prevRoas !== null ? round($prevRoas, 2) : null,
                'spendDelta' => $spendDelta,
                'verdict'    => $verdict,
                'action'     => $this->action($verdict),
                // Evidence tag: 'early' until the campaign has spent enough in
                // THIS window (≥ SOLID_SPEND USD) for the verdict to be trusted.
                'confidence' => $c['spend_usd'] < self::SOLID_SPEND ? 'early' : 'solid',
            ];
        }

        $topCampaigns = array_slice($campaigns, 0, $limit);

        return [
            'platform'   => $platform,
            'windowDays' => $windowDays,
            'kpis'       => $this->kpis($acc, $accP),
            'waste'    => [
                'amount'   => round($usd ? $wasteUsd : $this->nativeWaste($cur), 2),
                'sharePct' => $acc['spend_usd'] > 0 ? round($wasteUsd / $acc['spend_usd'] * 100, 1) : null,
                'count'    => $wasteCount,
            ],
            'campaigns' => $topCampaigns,
            'totalCampaigns' => count($campaigns),
            'actions'   => $this->actionPlan($campaigns),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>  one entry per campaign_id (numeric keys after sort)
     */
    private function aggregate(int $brandId, string $platform, string $start, string $end): array
    {
        $rows = AdCampaignDailyMetric::query()
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->whereBetween('date', [$start, $end])
            ->groupBy('campaign_id')
            ->selectRaw("campaign_id,
                MAX(campaign_name) AS name,
                SUM(spend) AS spend_native,
                SUM(spend * COALESCE(fx_rate_to_usd, 1)) AS spend_usd,
                SUM(conversion_value * COALESCE(fx_rate_to_usd, 1)) AS value_usd,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(conversions) AS conversions")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->campaign_id] = [
                'campaign_id' => (string) $r->campaign_id,
                'name'        => (string) ($r->name ?? ''),
                'spend_native' => (float) $r->spend_native,
                'spend_usd'   => (float) $r->spend_usd,
                'value_usd'   => (float) $r->value_usd,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
                'conversions' => (int) $r->conversions,
            ];
        }

        return $out;
    }

    /**
     * Account totals. `spend` and `cpm` are in the DISPLAY currency ($usd flag)
     * so every money figure in the section (KPIs, waste, campaign rows) shares
     * one currency; `spend_usd` is kept for share/ratio math and `roas` is the
     * USD ratio so it's currency-correct in either mode.
     *
     * @param array<int|string, array<string, mixed>> $rows
     */
    private function totals(array $rows, bool $usd): array
    {
        $spendNative = 0.0;
        $spendUsd = 0.0;
        $valueUsd = 0.0;
        $impr = 0;
        $clk = 0;
        $conv = 0;
        foreach ($rows as $r) {
            $spendNative += $r['spend_native'];
            $spendUsd    += $r['spend_usd'];
            $valueUsd    += $r['value_usd'];
            $impr        += $r['impressions'];
            $clk         += $r['clicks'];
            $conv        += $r['conversions'];
        }

        $spend = $usd ? $spendUsd : $spendNative;

        return [
            'spend'       => $spend,
            'spend_usd'   => $spendUsd,
            'value_usd'   => $valueUsd,
            'impressions' => $impr,
            'clicks'      => $clk,
            'conversions' => $conv,
            'roas'        => $spendUsd > 0 ? $valueUsd / $spendUsd : null,
            'ctr'         => $impr > 0 ? $clk / $impr * 100 : null,
            'cpm'         => $impr > 0 ? $spend / $impr * 1000 : null,
        ];
    }

    /**
     * @param array<string, mixed> $acc
     * @param array<string, mixed>|null $prev
     * @return array<string, mixed>
     */
    private function kpis(array $acc, ?array $prev): array
    {
        return [
            'spend'     => $this->kpi($acc['spend'], $prev['spend'] ?? null),
            'purchases' => $this->kpi($acc['conversions'], $prev['conversions'] ?? null),
            'roas'      => $this->kpi($acc['roas'], $prev['roas'] ?? null, ratio: true),
            'ctr'       => $this->kpi($acc['ctr'], $prev['ctr'] ?? null),
            'cpm'       => $this->kpi($acc['cpm'], $prev['cpm'] ?? null),
        ];
    }

    /** @return array{value: float|int|null, previous: float|int|null, deltaPct: ?float} */
    private function kpi(float|int|null $value, float|int|null $prev, bool $ratio = false): array
    {
        return [
            'value'    => is_float($value) ? round($value, 2) : $value,
            'previous' => is_float($prev) ? round((float) $prev, 2) : $prev,
            'deltaPct' => $ratio ? null : $this->pct($value, $prev),
        ];
    }

    private function verdict(?float $roas, float $spendUsd, ?float $spendDelta): string
    {
        if ($spendUsd < self::MIN_SPEND) {
            return 'minor';
        }
        if ($roas === null || $roas < self::DEAD_ROAS) {
            return 'dead';
        }
        if ($roas < self::WEAK_ROAS) {
            return ($spendDelta !== null && $spendDelta > self::SCALING) ? 'scaling_loss' : 'weak';
        }
        if ($roas >= self::WIN_ROAS) {
            return 'winner';
        }

        return 'steady';
    }

    private function action(string $verdict): string
    {
        return match ($verdict) {
            'dead'         => 'Pause now',
            'scaling_loss' => 'Cap budget',
            'weak'         => 'Review / refresh',
            'winner'       => 'Scale',
            'steady'       => 'Hold',
            default        => 'Monitor',
        };
    }

    /**
     * Prioritised, rules-derived action list: stop the waste, cap the scalers,
     * fund the winners. The LLM layer expands these into prose; the items + the
     * money are real.
     *
     * @param array<int, array<string, mixed>> $campaigns
     * @return array<int, array<string, mixed>>
     */
    private function actionPlan(array $campaigns): array
    {
        $dead    = array_values(array_filter($campaigns, static fn ($c) => $c['verdict'] === 'dead'));
        $scaling = array_values(array_filter($campaigns, static fn ($c) => $c['verdict'] === 'scaling_loss'));
        $winners = array_values(array_filter($campaigns, static fn ($c) => $c['verdict'] === 'winner'));

        $deadSpend = array_sum(array_map(static fn ($c) => $c['spend'], $dead));

        // An action is only 'solid' when every campaign backing it cleared the
        // SOLID_SPEND evidence floor; one under-evidenced campaign tags the
        // whole action 'early' (accuracy over small windows).
        $conf = static fn (array $group): string => array_filter($group, static fn ($c) => ($c['confidence'] ?? 'solid') === 'early') === [] ? 'solid' : 'early';

        $plan = [];
        if ($dead !== []) {
            $plan[] = [
                'kind'  => 'stop',
                'title' => 'Pause ' . count($dead) . ' losing campaign' . (count($dead) === 1 ? '' : 's'),
                'body'  => 'Sub-1× ROAS — ' . round($deadSpend, 2) . ' in spend returning less than it costs.',
                'confidence' => $conf($dead),
            ];
        }
        if ($scaling !== []) {
            $plan[] = [
                'kind'  => 'fix',
                'title' => 'Cap ' . count($scaling) . ' scaling loss' . (count($scaling) === 1 ? '' : 'es'),
                'body'  => 'Budget grew while ROAS stayed under target — cap before the loss compounds.',
                'confidence' => $conf($scaling),
            ];
        }
        if ($winners !== []) {
            $plan[] = [
                'kind'  => 'scale',
                'title' => 'Fund ' . count($winners) . ' winner' . (count($winners) === 1 ? '' : 's'),
                'body'  => 'Running at 3×+ ROAS — the clearest place to put the freed budget.',
                'confidence' => $conf($winners),
            ];
        }

        return $plan;
    }

    /** @param array<int|string, array<string, mixed>> $cur */
    private function nativeWaste(array $cur): float
    {
        $w = 0.0;
        foreach ($cur as $c) {
            $roas = $c['spend_usd'] > 0 ? $c['value_usd'] / $c['spend_usd'] : null;
            if ($c['spend_usd'] >= self::MIN_SPEND && ($roas === null || $roas < self::DEAD_ROAS)) {
                $w += $c['spend_native'];
            }
        }

        return $w;
    }

    private function pct(float|int|null $cur, float|int|null $prev): ?float
    {
        if ($cur === null || $prev === null || (float) $prev === 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $prev) / (float) $prev * 100, 1);
    }
}
