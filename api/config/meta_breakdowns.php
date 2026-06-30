<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Meta insights breakdown axes — the dashboard "Audience" view
|--------------------------------------------------------------------------
| type => the Meta `breakdowns` list to request. Verified live against real
| ad data via `meta:diagnose-breakdown` (2026-06-29): user_segment_key returns
| prospecting / engaged / existing / unknown for Advantage+ Shopping spend;
| the rest are standard documented breakdowns. The dashboard maps the raw
| segment keys to labels (prospecting → "New audience", etc.).
|
| `audience` is synced daily (it's the default view); the others are
| backfill-on-demand via `meta:backfill-breakdown --type=...`.
|
| Placement has TWO axes on purpose. `placement_platform` (publisher_platform
| only → Facebook / Instagram / Audience Network / Messenger) is the default
| "Placement" view: ~4 buckets that sum to ~100% of spend, so Audience Network
| is visible instead of buried. `placement` keeps the granular
| publisher_platform × platform_position split for the "Placement detail" view —
| it's long-tailed (15-25 positions) so most spend lands in "Other" there.
*/

return [
    'audience'           => ['user_segment_key'],
    'age_gender'         => ['age', 'gender'],
    'placement_platform' => ['publisher_platform'],
    'placement'          => ['publisher_platform', 'platform_position'],
    'country'            => ['country'],
    'device'             => ['impression_device'],
];
