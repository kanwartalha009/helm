<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mandatory master_admin MFA (spec §08)
    |--------------------------------------------------------------------------
    | When true, the SPA AuthGate forces a master_admin with no MFA enrolled to
    | complete enrollment before any app route renders.
    |
    | TEMPORARILY DEFAULTED OFF (2026-06-19) at the client's request: the
    | Cloudways server clock runs ~45s behind UTC and the box has no sudo to
    | NTP-sync, so TOTP enrollment fails. Deferred to end of project.
    |
    | TO RE-ENABLE: get Cloudways to NTP-sync the server clock, then set
    | HELM_REQUIRE_ADMIN_MFA=true in api/.env and run `php artisan config:cache`
    | (or flip this default back to true). The enrollment flow + ±60s window +
    | `php artisan mfa:reset` escape hatch are all already in place.
    */
    'require_admin_mfa' => env('HELM_REQUIRE_ADMIN_MFA', false),

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
