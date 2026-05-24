<?php

declare(strict_types=1);

/**
 * Sync orchestration config.
 *
 * - schedule:  preferences for the daily + hourly + cleanup runs
 * - retry:     per-job retry config (Horizon also honors these)
 * - hot_brands_limit: how many "hot" brands the hourly job covers
 * - currency_groupings: aggregation rules used by the dashboard.
 *     Sweden is hardcoded out of EUR aggregations per agency policy
 *     (see docs/10-edge-cases / Sweden exclusion).
 */
return [
    'schedule' => [
        'daily_at_utc'      => '13:00',
        'fx_at_utc'         => '13:30',
        'hourly_between'    => ['06:00', '22:00'],
        'cleanup_weekly_at' => '02:00',
        'cleanup_keep_days' => 90,
    ],

    'retry' => [
        'tries'        => 3,
        'backoff_secs' => [60, 300, 900],
        'timeout_secs' => 600,
        'memory_mb'    => 256,
    ],

    'hot_brands_limit' => 20,

    // FX provider + currency_groupings removed in Phase 1. Sync stores
    // native currency only; the dashboard renders each brand in its own
    // currency. Restore both blocks if/when USD aggregation comes back.
];
