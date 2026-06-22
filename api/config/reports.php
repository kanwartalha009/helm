<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Report registry
|--------------------------------------------------------------------------
| Maps a stable report key to the ReportType implementation that builds it.
| Mirrors config/platforms.php. New report types (country, product, meta
| audit) register here — the API and the SPA discover them automatically.
*/

return [
    'types' => [
        'overall-performance' => \App\Reports\OverallPerformance\OverallPerformanceReport::class,
    ],
];
