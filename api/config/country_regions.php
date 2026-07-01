<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Country → region map for the dashboard Audience "Region" view
|--------------------------------------------------------------------------
| Meta's country breakdown returns ISO-3166 alpha-2 codes (GB, US, FR, …).
| The Region view (AudienceQuery) groups those into a handful of regions so
| the long tail of 50+ shipping countries collapses into ~6 buckets that
| reconcile to ~100% of spend — killing the huge "Other" the country-level
| view has. Any code not listed here falls back to "Other".
|
| Kept intentionally coarse and DTC-oriented (UK/Ireland split out from the
| rest of Europe since it's usually a brand's biggest single market).
*/

return [
    'labels' => [
        'uk_ie'  => 'UK & Ireland',
        'europe' => 'Europe',
        'na'     => 'North America',
        'latam'  => 'Latin America',
        'apac'   => 'Asia-Pacific',
        'mea'    => 'Middle East & Africa',
        'other'  => 'Other',
    ],

    // ISO-2 (upper-case) => region key above.
    'map' => [
        // UK & Ireland
        'GB' => 'uk_ie', 'IE' => 'uk_ie', 'IM' => 'uk_ie', 'JE' => 'uk_ie', 'GG' => 'uk_ie',

        // Europe (EU + EFTA + wider continent)
        'AT' => 'europe', 'BE' => 'europe', 'BG' => 'europe', 'HR' => 'europe', 'CY' => 'europe',
        'CZ' => 'europe', 'DK' => 'europe', 'EE' => 'europe', 'FI' => 'europe', 'FR' => 'europe',
        'DE' => 'europe', 'GR' => 'europe', 'HU' => 'europe', 'IS' => 'europe', 'IT' => 'europe',
        'LV' => 'europe', 'LI' => 'europe', 'LT' => 'europe', 'LU' => 'europe', 'MT' => 'europe',
        'NL' => 'europe', 'NO' => 'europe', 'PL' => 'europe', 'PT' => 'europe', 'RO' => 'europe',
        'SK' => 'europe', 'SI' => 'europe', 'ES' => 'europe', 'SE' => 'europe', 'CH' => 'europe',
        'UA' => 'europe', 'RS' => 'europe', 'BA' => 'europe', 'AL' => 'europe', 'MK' => 'europe',
        'ME' => 'europe', 'MD' => 'europe', 'XK' => 'europe', 'AD' => 'europe', 'MC' => 'europe',

        // North America
        'US' => 'na', 'CA' => 'na',

        // Latin America
        'MX' => 'latam', 'BR' => 'latam', 'AR' => 'latam', 'CL' => 'latam', 'CO' => 'latam',
        'PE' => 'latam', 'UY' => 'latam', 'EC' => 'latam', 'CR' => 'latam', 'PA' => 'latam',
        'DO' => 'latam', 'GT' => 'latam', 'BO' => 'latam', 'PY' => 'latam', 'VE' => 'latam',
        'PR' => 'latam',

        // Asia-Pacific
        'AU' => 'apac', 'NZ' => 'apac', 'JP' => 'apac', 'CN' => 'apac', 'KR' => 'apac',
        'SG' => 'apac', 'HK' => 'apac', 'TW' => 'apac', 'IN' => 'apac', 'ID' => 'apac',
        'MY' => 'apac', 'TH' => 'apac', 'PH' => 'apac', 'VN' => 'apac', 'BD' => 'apac',
        'PK' => 'apac', 'LK' => 'apac',

        // Middle East & Africa
        'AE' => 'mea', 'SA' => 'mea', 'QA' => 'mea', 'KW' => 'mea', 'BH' => 'mea',
        'OM' => 'mea', 'IL' => 'mea', 'TR' => 'mea', 'EG' => 'mea', 'MA' => 'mea',
        'ZA' => 'mea', 'NG' => 'mea', 'KE' => 'mea', 'TN' => 'mea', 'DZ' => 'mea',
        'JO' => 'mea', 'LB' => 'mea', 'GH' => 'mea',
    ],
];
