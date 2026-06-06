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

    // FX / currency conversion. The native value is always stored; the USD
    // rate is snapshotted onto each daily_metrics row at sync time and the
    // dashboard's USD toggle reads `revenue * fx_rate_to_usd` in SQL. Rates
    // are populated nightly by FetchDailyCurrencyRatesJob (fx_at_utc) and on
    // demand by FxService. USD aggregation is a Phase 1 acceptance item
    // (docs/12-acceptance), so this block must stay populated.
    'fx' => [
        'target'       => 'USD',
        // Provider must honour the /{date}?from=&to= contract (frankfurter.app
        // by default — ECB reference rates, daily, no API key).
        'provider_url' => env('FX_PROVIDER_URL', 'https://api.frankfurter.app'),
        // Seed currencies fetched nightly even before a brand uses them, so a
        // day-one sync hits a cached rate instead of an on-demand provider call.
        // (Sweden's SEK still converts to USD; the Sweden exclusion only applies
        // to EUR groupings, which return in Phase 2.)
        'currencies'   => ['EUR', 'GBP', 'SEK', 'DKK', 'NOK', 'AED', 'SAR', 'CAD', 'AUD'],
        // Hard-pegged currencies the ECB / frankfurter feed doesn't cover.
        // Value = units of the currency per 1 USD (the official peg); FxService
        // uses 1 / peg as the USD rate and never calls the provider for these.
        // AED pegged at 3.6725 since 1997; SAR at 3.75 since 1986.
        'pegs'         => [
            'AED' => 3.6725,
            'SAR' => 3.75,
        ],
    ],

    // Shopify order scoping. The dashboard's "Total sales" must match the
    // client's report, which is filtered to the Online Store sales channel.
    // source_name:web = Online Store; set SHOPIFY_SALES_CHANNEL_QUERY empty to
    // count every channel.
    'shopify' => [
        'sales_channel_query' => env('SHOPIFY_SALES_CHANNEL_QUERY', 'source_name:web'),
    ],
];
