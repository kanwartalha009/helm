<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ads Library configuration (docs/feature-specs/ads-library.md)
|--------------------------------------------------------------------------
| Central, env-overridable knobs for both libraries. The internal winners
| library reuses AdAudit::MIN_SPEND / SOLID_SPEND for its evidence gates — those
| are NOT redefined here (single source of truth). This file owns the market
| library's Signal Score weights, corpus window, API call budget, default
| countries, retention note, and the Phase 4 tag taxonomy.
|
| Product lens (D-022 — Helm is sold to other agencies): `default_countries` is a
| DEFAULT only; the live value is a PER-WORKSPACE setting (a UK agency sets
| ['GB']). The Ad Library token is likewise a per-workspace credential. Neither is
| a shared, cross-tenant identity — ToS accountability + rate limits isolate per
| tenant. Cross-tenant pooling of the corpus percentiles/benchmarks is forbidden.
*/

return [

    // Signal Score weights for MARKET ads [HELM DEFAULT — no published standard].
    // Disclosed verbatim in the UI tooltip. The score is a SORT KEY, never shown
    // as performance (commercial EU ads expose no spend/impressions — §2).
    //     signal = longevity·w1 + reach·w2 + variants·w3
    'score' => [
        'longevity_weight' => (float) env('ADLIB_W_LONGEVITY', 0.45),
        'reach_weight'     => (float) env('ADLIB_W_REACH', 0.30),
        'variants_weight'  => (float) env('ADLIB_W_VARIANTS', 0.25),
    ],

    // Percentiles compare like-with-like within this trailing window (niche ×
    // country × window), so a fresh ad isn't ranked against a year of history.
    'corpus_window_days' => (int) env('ADLIB_CORPUS_DAYS', 90),

    // Official Ad Library API pull budget. Secondary sources report an ~200/hr
    // unpublished cap; stay clear. On hitting the budget the refresh SLEEPS to the
    // next hour and resumes — never blows the limit (§Phase 2).
    'call_budget_per_hour' => (int) env('ADLIB_CALL_BUDGET', 150),

    'refresh' => [
        'nightly_at_utc'            => env('ADLIB_REFRESH_AT', '02:30'),
        'hard_stop_utc'             => env('ADLIB_REFRESH_STOP', '06:00'),
        'page_ids_per_chunk'        => 10, // ads_archive hard max: 10 page ids per call
        'pages_per_chunk_per_night' => 5,  // cursor pages per chunk; log() when truncated
    ],

    // Default EU delivery countries for market searches. PER-WORKSPACE override
    // wins at read time (WorkspaceSetting 'adlib_countries'); this is the fallback.
    'default_countries' => ['ES'],

    // EU ads leave Meta's archive after ~1 year — keeping our own text+metadata
    // copy of tracked ads is the whole point (history survives Meta's deletion).
    'retention_note' => 'The EU Ad Library keeps commercial ads for ~1 year. Helm stores its own text + metadata copy of tracked ads so tracked history survives Meta deletion. No media files are stored (Ad Library ToS: batch download not permitted).',

    // Phase 4 tag taxonomy — starter suggestions for the board tagger (operator
    // always confirms; an LLM may propose tags from public/creative text only).
    'tags' => [
        'hooks'   => ['problem-callout', 'social-proof', 'founder-story', 'before-after', 'price-anchor', 'unboxing', 'testimonial'],
        'formats' => ['ugc-video', 'static-product', 'carousel', 'meme', 'testimonial'],
        'angles'  => ['price', 'quality', 'convenience', 'status', 'urgency', 'scarcity'],
    ],

];
