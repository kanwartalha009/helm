<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mandatory master_admin MFA (spec §08)
    |--------------------------------------------------------------------------
    | When true, the SPA AuthGate forces a master_admin with no MFA enrolled to
    | complete enrollment before any app route renders.
    |
    | Login itself never blocks a secret-less admin (they still get a token,
    | then AuthGate routes them to /mfa/setup), so turning this on can't lock
    | anyone out.
    |
    | RE-ENABLED (2026-07-21): the original blocker — the Cloudways clock runs
    | ~45s behind UTC with no sudo/NTP — is now corrected in the application
    | layer (App\Support\NtpTime): TOTP validates against an NTP-corrected
    | timestamp, so codes work despite the drifting OS clock. Enrollment issues
    | recovery codes, and `php artisan mfa:reset` remains an admin escape hatch.
    | Set HELM_REQUIRE_ADMIN_MFA=false to disable in an emergency.
    */
    'require_admin_mfa' => env('HELM_REQUIRE_ADMIN_MFA', true),

    /*
    |--------------------------------------------------------------------------
    | 2FA (TOTP) verification + clock correction
    |--------------------------------------------------------------------------
    | window: time-steps checked either side of "now" (±1 = ±30s). Kept tight
    |   because NtpTime corrects the clock, so a wide, weaker window isn't
    |   needed.
    | ntp:    application-level clock sync so TOTP works on a host we can't
    |   NTP-sync at the OS level. enabled=false falls back to the raw system
    |   clock (offset 0) — used in tests so no network call fires.
    */
    'mfa' => [
        'window' => (int) env('HELM_MFA_WINDOW', 1),
        'ntp'    => [
            'enabled'    => env('HELM_MFA_NTP', true),
            'host'       => env('HELM_MFA_NTP_HOST', 'time.google.com'),
            'timeout'    => (int) env('HELM_MFA_NTP_TIMEOUT', 2),
            'cache_ttl'  => (int) env('HELM_MFA_NTP_TTL', 3600),
            'max_offset' => (int) env('HELM_MFA_NTP_MAX_OFFSET', 3600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard aggregation engine
    |--------------------------------------------------------------------------
    | 'legacy' — DashboardQuery, ~12 queries per brand (the shipped behavior).
    | 'set'    — DashboardQuerySetBased, a constant handful of GROUP BY
    |            brand_id queries regardless of brand count (audit 2026-07-10).
    |
    | Flip AFTER a clean run of `php artisan helm:dashboard-parity` against
    | production data: set HELM_DASHBOARD_ENGINE=set in api/.env, then
    | `php artisan config:cache`. Roll back by removing the var again.
    */
    'dashboard_engine' => env('HELM_DASHBOARD_ENGINE', 'legacy'),
];
