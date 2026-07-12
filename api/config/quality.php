<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Data-quality score (master plan §4.3) — GO-1.3
|--------------------------------------------------------------------------
| A per-brand 0–100 score composed ONLY of things Helm can MEASURE. No vibes, no
| hand-tuned "health" fudge: every component maps to a countable fact (a connection
| exists / a row's date / a null cost), and every component reports the exact gap
| plus the backfill that closes it.
|
| Why it exists: GO-3/GO-4 recommendations are GATED on it (`threshold`, default 70).
| A strategist that advises confidently on holey data is the generic-advice failure
| mode that killed trust in every incumbent — so Helm refuses to advise until the
| data underneath is good enough, and says exactly what's missing.
|
| Applicability: a component that CANNOT apply to a brand (ad-grain coverage on a
| brand with no ad platform) is dropped from the denominator rather than scored 0 —
| punishing a brand for a grain it cannot have would be a wrong number.
*/

return [

    // Component weights. Applicable weights are re-normalised to 100.
    'weights' => [
        'platforms' => 20,   // the expected connections exist
        'freshness' => 25,   // each connected source has a recent COMPLETE day
        'history'   => 20,   // backfill depth vs the 12-month target
        'grain'     => 20,   // campaign / ad-set / creative rows behind the ad spend
        'costs'     => 15,   // a cost basis exists, so margin is real
    ],

    // Recommendations (GO-3/GO-4) require a score >= this.
    'threshold' => (int) env('HELM_QUALITY_THRESHOLD', 70),

    // Freshness: a source this many days stale scores 0 for its share; 0–grace days
    // score full, and it decays linearly in between.
    'freshness_grace_days' => 1,
    'freshness_zero_days'  => 7,

    // History target — same 12 months the coverage card backfills to.
    'history_target_months' => 12,

    // Cost coverage is measured against the products that actually earned revenue in
    // this trailing window (costing a dead SKU proves nothing).
    'costs_window_days' => 90,

    // A brand-level gross_margin_pct is a REAL but coarse basis: it caps the cost
    // component here, because a brand-wide rate is not a per-product cost.
    'brand_margin_credit' => 0.5,

];
