<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Klaviyo integration (growth-os-master-plan §3.1 / §4.1) — GO-1.1
|--------------------------------------------------------------------------
| Primary-sourced 2026-07-11 against the Klaviyo API. Per-brand PRIVATE key
| (Authorization: Klaviyo-API-Key pk_…), stored per brand in platform_credentials
| (platform='klaviyo', key='private_key', brand_id set). OAuth is the later
| marketplace-grade upgrade — the credential layer is built so it can slot in.
|
| Honesty law (§0.1): Klaviyo revenue is LAST-TOUCH within Klaviyo's own windows;
| it OVERLAPS ad-platform and organic revenue. It renders as its OWN channel column
| with `honesty_box` shown alongside — NEVER summed into a "total attributed" figure.
*/

return [

    'base'     => env('KLAVIYO_BASE', 'https://a.klaviyo.com/api/'),
    // ISO-date versioning: 1yr stable + 1yr deprecated. Re-verify at build time.
    'revision' => env('KLAVIYO_REVISION', '2026-04-15'),

    // The conversion metric whose sum_value = email-attributed revenue. Resolved to
    // an id per account via GET /api/metrics/ (name match, cached per brand run).
    'conversion_metric' => env('KLAVIYO_CONVERSION_METRIC', 'Placed Order'),

    // No reporting data exists before this date; query windows are ≤ 1 year.
    'data_floor' => '2023-06-01',

    // Documented limits (informational — the client backs off on 429/Retry-After):
    //   metric-aggregates: burst 3/s, steady 60/m.
    //   campaign/flow values-reports: 225 calls/day/account (weekly reconcile only).
    'rate' => [
        'aggregates_burst_per_s' => 3,
        'aggregates_steady_per_m' => 60,
        'values_report_per_day'   => 225,
    ],

    // Shown VERBATIM wherever a Klaviyo number renders (dashboard column, report
    // section, weekly block). This is the attribution honesty box, not decoration.
    'honesty_box' => 'Email revenue is Klaviyo-attributed (last-touch within Klaviyo\'s own '
        . 'click/open windows, default ~5 days). It overlaps ad-platform and organic revenue, '
        . 'so it is shown as its own channel — never added to store or ad revenue. Klaviyo applies '
        . 'attribution-window changes retroactively, so past figures can shift on re-sync.',

];
