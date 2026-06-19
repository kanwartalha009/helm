<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mandatory master_admin MFA (spec §08)
    |--------------------------------------------------------------------------
    | When true, the SPA AuthGate forces a master_admin with no MFA enrolled to
    | complete enrollment before any app route renders. Set
    | HELM_REQUIRE_ADMIN_MFA=false (then `php artisan config:cache`) to release
    | the gate — the anti-lockout escape if enrollment can't be completed
    | (e.g. server clock skew breaking TOTP). Re-enable once resolved.
    */
    'require_admin_mfa' => env('HELM_REQUIRE_ADMIN_MFA', true),
];
