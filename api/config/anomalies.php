<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Anomaly feed (master plan §5.4) — GO-2.4
|--------------------------------------------------------------------------
| Deterministic rules ONLY. No LLM, no learned model, no black box: every anomaly is
| "this number moved X% against its own 28-day median, and the threshold is Y%".
| An alert a human cannot re-derive by hand is an alert a human will not trust — and
| an alert stream nobody trusts is worse than no alerts, because it trains people to
| ignore the one that mattered.
|
| Comparison basis is the trailing-28-day MEDIAN, not the mean: one Black-Friday day
| would drag a mean far enough to suppress real alerts for weeks afterwards.
|
| `min_days` guards every rule: with too few complete days there IS no baseline, so
| the rule does not fire. Silence beats a confident alert computed from three days.
|
| [HELM DEFAULT] — no published industry standard exists for these thresholds. They are
| starting points, tuned from operator feedback, and they live here so tuning never
| requires a code change.
*/

return [

    // Complete days of history required in the trailing window before ANY rule fires.
    'min_days' => (int) env('HELM_ANOMALY_MIN_DAYS', 14),

    'window_days' => 28,

    // Rules. `enabled` lets an operator silence a noisy rule without a deploy.
    'rules' => [

        // Ad costs rising against their own baseline.
        'cpm_spike'  => ['enabled' => true, 'threshold_pct' => 40, 'severity' => 'warn'],
        'cpa_spike'  => ['enabled' => true, 'threshold_pct' => 50, 'severity' => 'warn'],

        // Efficiency falling.
        'roas_drop'  => ['enabled' => true, 'threshold_pct' => 35, 'severity' => 'warn'],

        // Budget moving sharply — not automatically bad, but always worth knowing.
        'spend_spike' => ['enabled' => true, 'threshold_pct' => 75, 'severity' => 'info'],

        // A connected platform that spent money every day and today spent nothing:
        // usually a paused campaign, a billing failure, or a broken connection.
        'zero_delivery' => ['enabled' => true, 'severity' => 'critical'],

        // Money being spent driving traffic to something that cannot be bought.
        'stockout_on_ads' => [
            'enabled' => true,
            'min_spend_usd' => 50,   // evidence floor — don't shout about €3 of spend
            'lookback_days' => 7,
            'severity' => 'critical',
        ],

        // Tracking health: the gap between what platforms CLAIM they drove and what the
        // store actually took, moving sharply against its own baseline. A jump here
        // usually means a pixel/CAPI break or an attribution-window change — not a real
        // performance change. It is a signal about the DATA, not about the ads.
        'mer_divergence' => ['enabled' => true, 'threshold_pct' => 50, 'severity' => 'warn'],
    ],

];
