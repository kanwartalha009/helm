<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Forecast baseline (master plan §5.3 / §3.4) — GO-2.3
|--------------------------------------------------------------------------
| Method: SEASONAL NAIVE + DRIFT. "Each forecast equals the last observed value from
| the same season", adjusted by a trailing trend term — Hyndman & Athanasopoulos,
| Forecasting: Principles and Practice (fpp3) §5.2, where both are named as legitimate
| benchmark methods: https://otexts.com/fpp3/simple-methods.html
|
| Zero new dependencies: pure SQL + arithmetic (§0 — no new composer deps).
|
| THE REFUSAL IS THE FEATURE. A forecast built on absent history is not a cautious
| estimate, it is a fabrication — and a fabricated number in a plan destroys the
| credibility the whole product is built on. So this engine REFUSES rather than
| extrapolates whenever:
|   - the brand has less than `min_history_days` of data at all, or
|   - last year's coverage of the forecast window is below `min_lastyear_coverage_pct`.
| In those cases it returns status='insufficient_history' and renders nothing.
|
| Every output carries `label` verbatim. A forecast without that label must never ship.
*/

return [

    'horizon_days' => (int) env('HELM_FORECAST_HORIZON', 90),

    // Never extrapolate from a thin brand. Below this, we refuse outright.
    'min_history_days' => (int) env('HELM_FORECAST_MIN_HISTORY', 90),

    // Last year must actually cover the window we're forecasting into. Below this
    // share of the horizon's days, the seasonal term is guesswork → refuse.
    'min_lastyear_coverage_pct' => (int) env('HELM_FORECAST_MIN_COVERAGE', 70),

    // Drift term: trailing window compared with the SAME window a year earlier.
    'trend_window_days' => 28,

    // Both trend windows need this many COMPLETE days or the trend is not computed
    // (the forecast then falls back to pure seasonal-naive and says so).
    'trend_min_complete_days' => 21,

    // A trend multiplier outside this range is almost always an artefact (a brand that
    // 10×'d because last year was near-zero), not a signal. Clamp it and disclose that
    // it was clamped rather than shipping an absurd projection.
    'trend_clamp' => ['min' => 0.5, 'max' => 2.0],

    // Rendered VERBATIM wherever a forecast number appears (master plan §0 law 1:
    // Verified / Proxy / Modeled — never mix them silently).
    'label' => 'Modeled — baseline forecast (seasonal-naive + trend)',

    'method_note' => 'Each day is projected from the same date last year (seasonal-naive), scaled by how the '
        . 'last 28 days compare with the same 28 days a year ago (the trend term). It assumes next year looks '
        . 'like last year, adjusted for current momentum — it knows nothing about a launch, a stockout or a '
        . 'campaign you have planned. Treat it as a baseline to beat, not a promise.',

];
