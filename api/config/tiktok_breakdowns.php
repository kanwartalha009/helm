<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TikTok audience breakdown axes
|--------------------------------------------------------------------------
|
| breakdown_type => the TikTok report `dimensions` list (report_type=AUDIENCE).
| Mirrors config/meta_breakdowns.php but with TikTok's dimension names, which
| differ from Meta's. These are the documented v1.3 audience dimensions; if one
| is wrong for a live account the whole report call fails, so the fetcher falls
| back and that axis just doesn't sync (logged), never breaking the day.
|
| Validate the names with `php artisan tiktok:diagnose` (it probes candidate
| dimensions and prints which are valid). TikTok lacks Meta's ASC "audience"
| (new/returning) and its placement axes, so those aren't listed.
|
*/
return [
    'country'    => ['country_code'],
    'age'        => ['age'],
    'gender'     => ['gender'],
    'age_gender' => ['age', 'gender'],
    'device'     => ['platform'], // TikTok's `platform` dimension = OS (Android / iOS)
];
