<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MoM Strategy Report ("mom") — section catalog + benchmark defaults
|--------------------------------------------------------------------------
| M1 (2026-07-14): the CODE-DEFAULT layer for ReportLayouts resolution
| (brand override -> agency default -> this file), and the catalog M1's
| customizer reorders/toggles. M2-M4 build the actual section endpoints;
| this file is written now so the customizer has real section keys to
| operate on the moment M1 ships, per docs/feature-specs/monthly-report-v2-mom.md.
|
| Order below mirrors the spec's default meeting order: S-EX first (REV2 R4),
| then S-GOALS (REV2 R5), then S1-S12 (M2), S13-S18 (M3), S0/S19 (M4).
|
| 'view' is the REV2 R2 default per-section view ('chart'|'table'|'both') —
| a brand's report_layouts row can override it; this is only the code default.
*/

return [
    'sections' => [
        ['key' => 'S-EX',    'label' => 'Executive overview',            'view' => 'chart', 'enabled' => true],
        ['key' => 'S-GOALS', 'label' => 'Goals vs actual',                'view' => 'chart', 'enabled' => true],
        ['key' => 'S1',      'label' => 'Financial matrix',               'view' => 'both',  'enabled' => true],
        ['key' => 'S2',      'label' => 'Total sales evolution',          'view' => 'chart', 'enabled' => true],
        // S3 "New vs returning evolution" retired (Kanwar, 2026-07-15) — the
        // new/returning percentage split lives in S-EX (Executive overview)
        // instead of a standalone section.
        ['key' => 'S4',      'label' => 'Market revenue by tier',         'view' => 'both',  'enabled' => true],
        ['key' => 'S5',      'label' => 'Country revenue MoM',            'view' => 'table', 'enabled' => true],
        ['key' => 'S6',      'label' => 'ROAS by country',                'view' => 'both',  'enabled' => true],
        ['key' => 'S7',      'label' => 'Best categories MoM/YoY',        'view' => 'both',  'enabled' => true],
        ['key' => 'S8',      'label' => 'Best sellers MoM',                'view' => 'table', 'enabled' => true],
        ['key' => 'S9',      'label' => 'Sessions & CR YoY',              'view' => 'chart', 'enabled' => true],
        ['key' => 'S10',     'label' => 'Funnel by country',              'view' => 'table', 'enabled' => true],
        ['key' => 'S11',     'label' => 'Funnel by landing path',         'view' => 'table', 'enabled' => true],
        ['key' => 'S12',     'label' => 'Prior-year next-month lookback', 'view' => 'chart', 'enabled' => true],
        ['key' => 'S13',     'label' => 'Audience: new vs existing spend','view' => 'both',  'enabled' => true],
        ['key' => 'S14',     'label' => 'Placement mix',                  'view' => 'both',  'enabled' => true],
        ['key' => 'S15',     'label' => 'Gender mix',                     'view' => 'chart', 'enabled' => true],
        ['key' => 'S16',     'label' => 'Awareness country concentration','view' => 'both',  'enabled' => true],
        ['key' => 'S17',     'label' => 'Landing spend x best sellers',   'view' => 'table', 'enabled' => true],
        ['key' => 'S18',     'label' => 'Klaviyo attribution + list growth', 'view' => 'both', 'enabled' => true],
        ['key' => 'S0',      'label' => 'Next steps',                     'view' => 'table', 'enabled' => true],
        ['key' => 'S19',     'label' => 'Novedades',                      'view' => 'table', 'enabled' => true],
    ],

    // PDF defaults cited in the spec's Kanwar/Bosco-owed list — UNCONFIRMED.
    // Bosco has not yet confirmed these; do not treat as final. M2/M3 read
    // these for the benchmark chip in each section's header.
    'benchmarks' => [
        // S14 placement mix — "vertical placement %" (Stories+Reels) goal.
        'vertical_placement_pct_goal' => 80.0,
        // S13 audience mix — existing-customer spend share benchmark (lower is better).
        'existing_spend_pct_benchmark' => 15.0,
        // S18 Klaviyo — email revenue share benchmark.
        'klaviyo_revenue_pct_benchmark' => 50.0,
        // S5/S6 country ROAS status thresholds — [HELM DEFAULT], not from the PDF.
        'roas_alarm_floor' => 1.5,
        // S16 awareness country concentration — [HELM DEFAULT], not from the PDF.
        // Flag when one country carries more than this share of awareness spend.
        'awareness_country_concentration_pct' => 50.0,
    ],
];
