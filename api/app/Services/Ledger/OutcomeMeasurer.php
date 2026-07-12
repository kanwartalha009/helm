<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\Recommendation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The engine grades ITSELF (GO-3.3, master plan §6.3).
 *
 * 14 and 30 days after an operator decided on a recommendation, this job measures what
 * actually happened to the metric that recommendation was about, and writes the outcome
 * to the ledger — once, permanently.
 *
 * ══ IT MUST BE ABLE TO SAY HELM WAS WRONG ══
 * Every rule below has a real path to 'worsened' and a real path to 'flat'. A scoring
 * function tuned so everything lands in "improved" produces a win-rate that means
 * nothing — a lie with a decimal point on it. The whole reason a track record is worth
 * anything is that it COULD have come out badly.
 *
 * Two specific pieces of honesty are load-bearing:
 *
 *  1. **An accepted 'pause' that was never actually paused is NOT a win.** If spend kept
 *     flowing after the operator agreed, the waste was not avoided. Helm does not get to
 *     claim credit for advice nobody executed. (`pause_spend_drop_pct`)
 *
 *  2. **'unmeasurable' is a real answer and stays in the denominator.** The campaign was
 *     deleted, the product delisted, the subject vanished. Recording that honestly costs
 *     a few points of win-rate; silently dropping those rows would inflate it.
 */
class OutcomeMeasurer
{
    private const AD_PLATFORMS = ['meta', 'google', 'tiktok'];

    /**
     * Measure everything that is due, and expire stale open advice.
     *
     * @return array{measured: int, expired: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();
        $cfg = (array) config('ledger.measurement', []);

        $expired = $this->expireStaleOpenAdvice($now, (int) ($cfg['expire_open_after_days'] ?? 30));

        // Decided ≥14 days ago and not yet graded.
        $due = Recommendation::query()
            ->whereIn('status', ['accepted', 'dismissed'])
            ->whereNull('outcome')
            ->whereNotNull('status_at')
            ->where('status_at', '<=', $now->subDays((int) ($cfg['windows'][0] ?? 14))->toDateTimeString())
            ->with('brand')
            ->limit(500)
            ->get();

        $measured = 0;
        $ledger   = app(Ledger::class);

        foreach ($due as $rec) {
            if ($rec->brand === null) {
                continue;
            }

            $result = $this->measureOne($rec, $now, $cfg);
            if ($result === null) {
                continue; // not enough elapsed yet for a fair read
            }

            $ledger->measure($rec, $result['outcome'], $result['value14d'], $result['value30d']);
            $measured++;
        }

        return ['measured' => $measured, 'expired' => $expired];
    }

    /**
     * Open advice nobody acted on eventually expires. It stays in the ledger and in the
     * acceptance-rate denominator — pretending the recommendation was never made would
     * flatter the number.
     */
    private function expireStaleOpenAdvice(CarbonImmutable $now, int $afterDays): int
    {
        $stale = Recommendation::query()
            ->where('status', 'open')
            ->where('created_at', '<=', $now->subDays($afterDays)->toDateTimeString())
            ->limit(500)
            ->get();

        $ledger = app(Ledger::class);
        foreach ($stale as $rec) {
            $ledger->transition($rec, 'expired', null, 'No decision within ' . $afterDays . ' days.');
        }

        return $stale->count();
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array{outcome: string, value14d: ?float, value30d: ?float}|null
     */
    private function measureOne(Recommendation $rec, CarbonImmutable $now, array $cfg): ?array
    {
        $decidedAt = CarbonImmutable::parse($rec->status_at)->startOfDay();
        $w         = (array) ($cfg['windows'] ?? [14, 30]);

        $v14 = $this->metricAfter($rec, $decidedAt, (int) $w[0]);
        $v30 = $decidedAt->addDays((int) ($w[1] ?? 30))->lessThanOrEqualTo($now)
            ? $this->metricAfter($rec, $decidedAt, (int) ($w[1] ?? 30))
            : null;

        // The subject produced NO data at all after the decision. Either it was removed,
        // or it never ran again. Honest bucket — and it still counts in the denominator.
        if ($v14 === null && $v30 === null) {
            return ['outcome' => 'unmeasurable', 'value14d' => null, 'value30d' => null];
        }

        // Grade on the longest window we actually have.
        $value = $v30 ?? $v14;

        $outcome = match ($rec->kind) {
            'pause'            => $this->gradePause($rec, $decidedAt, (int) $w[0], $cfg),
            'scale'            => $this->gradeDirectional($rec, $value, $cfg, higherIsBetter: true),
            'creative_refresh' => $this->gradePause($rec, $decidedAt, (int) $w[0], $cfg), // refresh = the stale ad stops spending
            default            => $this->gradeDirectional($rec, $value, $cfg, higherIsBetter: $rec->outcome_metric !== 'cpa'),
        };

        return ['outcome' => $outcome, 'value14d' => $v14, 'value30d' => $v30];
    }

    /**
     * A pause (or a creative retirement) worked if the money actually STOPPED.
     *
     * If the operator accepted and then never paused it, spend kept flowing, the waste
     * was not avoided, and Helm does not get to book a win for advice nobody carried out.
     * That is the difference between a track record and a marketing number.
     *
     * @param array<string, mixed> $cfg
     */
    private function gradePause(Recommendation $rec, CarbonImmutable $decidedAt, int $days, array $cfg): string
    {
        $before = $this->subjectSpend($rec, $decidedAt->subDays($days), $decidedAt->subDay());
        $after  = $this->subjectSpend($rec, $decidedAt, $decidedAt->addDays($days));

        if ($before === null || $before <= 0.0) {
            return 'unmeasurable';  // nothing was being spent before — nothing to save
        }

        $dropPct = ($before - (float) $after) / $before * 100;
        $needed  = (float) ($cfg['pause_spend_drop_pct'] ?? 80);

        if ($dropPct >= $needed) {
            return 'improved';      // the money stopped: waste avoided
        }
        if ($dropPct <= 0.0) {
            return 'worsened';      // spend held or GREW after we said stop
        }

        return 'flat';              // partially wound down
    }

    /**
     * Did the metric move materially in the direction we said it should?
     * Inside the material band, the honest answer is FLAT.
     *
     * @param array<string, mixed> $cfg
     */
    private function gradeDirectional(Recommendation $rec, ?float $value, array $cfg, bool $higherIsBetter): string
    {
        $baseline = $rec->baseline_value;
        if ($baseline === null || $baseline <= 0.0 || $value === null) {
            return 'unmeasurable';  // no baseline to compare against — say so, don't guess
        }

        $deltaPct = ($value - (float) $baseline) / (float) $baseline * 100;
        $band     = (float) ($cfg['material_change_pct'] ?? 10);

        if (abs($deltaPct) < $band) {
            return 'flat';
        }

        $better = $higherIsBetter ? $deltaPct > 0 : $deltaPct < 0;

        return $better ? 'improved' : 'worsened';
    }

    /** The recommendation's outcome_metric, measured over the N days AFTER the decision. */
    private function metricAfter(Recommendation $rec, CarbonImmutable $decidedAt, int $days): ?float
    {
        $from = $decidedAt->toDateString();
        $to   = $decidedAt->addDays($days)->toDateString();

        return match ($rec->outcome_metric) {
            'spend_waste' => $this->subjectSpend($rec, $decidedAt, $decidedAt->addDays($days)),
            'cpa'         => $this->subjectRatio($rec, $from, $to, 'cpa'),
            'revenue'     => $this->subjectRevenue($rec, $from, $to),
            default       => $this->subjectRatio($rec, $from, $to, 'roas'),
        };
    }

    /** Spend attributable to the subject in a window. Null when the subject has no rows. */
    private function subjectSpend(Recommendation $rec, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $f = $from->toDateString();
        $t = $to->toDateString();

        $row = match ($rec->subject_type) {
            'adset' => DB::table('ad_set_daily_metrics')
                ->where('brand_id', $rec->brand_id)->where('ad_set_id', $rec->subject_id)
                ->whereBetween('date', [$f, $t])
                ->selectRaw('COALESCE(SUM(spend * COALESCE(fx_rate_to_usd,1)), 0) AS v, COUNT(*) AS n')->first(),

            'ad' => DB::table('ad_creative_daily')
                ->where('brand_id', $rec->brand_id)->where('ad_id', $rec->subject_id)
                ->whereBetween('date', [$f, $t])
                ->selectRaw('COALESCE(SUM(spend * COALESCE(fx_rate_to_usd,1)), 0) AS v, COUNT(*) AS n')->first(),

            // brand-level advice is scoped to a platform (subject_id = 'meta' etc.)
            default => DB::table('daily_metrics')
                ->where('brand_id', $rec->brand_id)
                ->whereIn('platform', in_array($rec->subject_id, self::AD_PLATFORMS, true) ? [$rec->subject_id] : self::AD_PLATFORMS)
                ->whereBetween('date', [$f, $t])
                ->selectRaw('COALESCE(SUM(spend * COALESCE(fx_rate_to_usd,1)), 0) AS v, COUNT(*) AS n')->first(),
        };

        return ((int) ($row->n ?? 0)) === 0 ? null : (float) ($row->v ?? 0);
    }

    /** ROAS or CPA for the subject over a window (USD math). Null when the subject vanished. */
    private function subjectRatio(Recommendation $rec, string $from, string $to, string $metric): ?float
    {
        $q = match ($rec->subject_type) {
            'adset' => DB::table('ad_set_daily_metrics')
                ->where('brand_id', $rec->brand_id)->where('ad_set_id', $rec->subject_id),
            'ad' => DB::table('ad_creative_daily')
                ->where('brand_id', $rec->brand_id)->where('ad_id', $rec->subject_id),
            default => DB::table('daily_metrics')
                ->where('brand_id', $rec->brand_id)
                ->whereIn('platform', in_array($rec->subject_id, self::AD_PLATFORMS, true) ? [$rec->subject_id] : self::AD_PLATFORMS),
        };

        $row = $q->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(spend * COALESCE(fx_rate_to_usd,1)), 0)            AS spend,
                COALESCE(SUM(conversion_value * COALESCE(fx_rate_to_usd,1)), 0) AS value,
                COALESCE(SUM(conversions), 0)                                   AS conv,
                COUNT(*) AS n
            ')->first();

        if (((int) ($row->n ?? 0)) === 0) {
            return null;   // vanished
        }

        $spend = (float) ($row->spend ?? 0);
        if ($spend <= 0.0) {
            return null;   // no spend → no ratio. Missing ≠ zero.
        }

        return $metric === 'cpa'
            ? (((int) $row->conv) > 0 ? $spend / (int) $row->conv : null)
            : (float) $row->value / $spend;
    }

    /** Store revenue attributable to a product (or the brand) after the decision. */
    private function subjectRevenue(Recommendation $rec, string $from, string $to): ?float
    {
        if ($rec->subject_type === 'product') {
            $row = DB::table('commerce_daily_metrics')
                ->where('brand_id', $rec->brand_id)
                ->where('dimension_type', 'product')
                ->where('dimension_key', $rec->subject_id)
                ->whereBetween('date', [$from, $to])
                ->selectRaw('COALESCE(SUM(COALESCE(total_sales,0) + COALESCE(refunds_amount,0)), 0) AS v, COUNT(*) AS n')
                ->first();
        } else {
            $row = DB::table('daily_metrics')
                ->where('brand_id', $rec->brand_id)->where('platform', 'shopify')
                ->whereBetween('date', [$from, $to])
                ->selectRaw('COALESCE(SUM(COALESCE(total_sales,0) + COALESCE(refunds_amount,0)), 0) AS v, COUNT(*) AS n')
                ->first();
        }

        return ((int) ($row->n ?? 0)) === 0 ? null : (float) ($row->v ?? 0);
    }
}
