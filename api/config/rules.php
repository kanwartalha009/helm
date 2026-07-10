<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Helm ratified rule set — single source of truth for every threshold
|--------------------------------------------------------------------------
| Spec: docs/feature-specs/product-audit-adset-underperformers.md §3.
|
| The UI and every report/rule engine MUST read thresholds from here — never
| hardcode a number in two places. Each value is tagged in a comment:
|   [SOURCED]      — published by a named source (see §2 of the spec); safe to
|                    cite to clients.
|   [HELM DEFAULT] — no published standard exists; a sensible default the agency
|                    can override. The UI must NEVER present these as an
|                    "industry benchmark".
| Every value is env-overridable so the agency can tune per deployment.
|
| Breakeven-ROAS and kill-by-CPA rules only activate when a brand has
| gross_margin_pct / target_cpa set (Phase 0); when null those rules are
| silently skipped, never guessed.
*/

return [
    // Products ---------------------------------------------------------
    'product' => [
        'decline_pct'        => (float) env('RULE_PRODUCT_DECLINE_PCT', 30),      // [HELM DEFAULT] revenue drop vs prior equal window
        'decline_floor_usd'  => (float) env('RULE_PRODUCT_DECLINE_FLOOR', 100),   // [HELM DEFAULT] ignore products smaller than this (per window, USD)
        'refund_warn_pct'    => (float) env('RULE_PRODUCT_REFUND_WARN', 15),      // money-based; see caveat in spec §3
        'refund_crit_pct'    => (float) env('RULE_PRODUCT_REFUND_CRIT', 25),      // apparel runs 20–40% units-based [SOURCED]
        'cover_low_days'     => (int)   env('RULE_PRODUCT_COVER_LOW', 28),        // stockout risk: <4 weeks cover while selling [SOURCED Luca/Prediko]
        'cover_high_days'    => (int)   env('RULE_PRODUCT_COVER_HIGH', 180),      // matches existing DeadInventory::SLOW_COVER_DAYS
        'concentration_pct'  => (float) env('RULE_PRODUCT_CONCENTRATION', 15),    // single product share, info-level [weakly SOURCED]
        'abc'                => ['a' => 80, 'b' => 95],                            // cumulative revenue share cutoffs [SOURCED Shopify]
    ],
    // Ad sets ----------------------------------------------------------
    'adset' => [
        'min_evidence_usd'   => (float) env('RULE_ADSET_MIN_EVIDENCE', 50),       // no verdict below this spend [SOURCED Bïrch]
        'kill_cpa_mult'      => (float) env('RULE_ADSET_KILL_CPA_MULT', 2.0),     // 0 purchases after spending ≥2× target CPA [SOURCED range 1.5–2.5]
        'frequency_warn'     => (float) env('RULE_ADSET_FREQ_WARN', 4.0),         // 7-day frequency [SOURCED]
        'ctr_floor_pct'      => (float) env('RULE_ADSET_CTR_FLOOR', 0.5),         // [SOURCED]
        'fragment_usd_day'   => (float) env('RULE_ADSET_FRAGMENT', 50),           // avg daily spend under this = fragmentation, info [SOURCED Madgicx]
        'budget_lost_is'     => (float) env('RULE_ADSET_BUDGET_LOST_IS', 0.10),   // Google: ≥10% impression share lost to budget [HELM DEFAULT on a SOURCED metric]
    ],
    // Store-level ------------------------------------------------------
    'store' => [
        'reconcile_warn_pct'  => (float) env('RULE_STORE_RECONCILE', 10),         // platform conv value vs store revenue variance [SOURCED Luca]
        'refund_baseline_mult' => (float) env('RULE_STORE_REFUND_SPIKE', 1.5),    // window refund-rate ≥1.5× trailing-90d baseline [HELM DEFAULT]
    ],
];
