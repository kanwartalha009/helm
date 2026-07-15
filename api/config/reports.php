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
        'monthly'             => \App\Reports\Monthly\MonthlyReport::class,
        'weekly'              => \App\Reports\Weekly\WeeklyReport::class,
        'creatives'           => \App\Reports\Creative\CreativeReport::class,
        'ads-audit'           => \App\Reports\AdsAudit\AdsAuditReport::class,
        // M2 (monthly-report-v2-mom.md) — SHELL only (month/layout/freshness).
        // Section data is fetched separately via MomSectionController; v1
        // ('monthly') is completely untouched by this entry (REV2 R7).
        'mom'                 => \App\Reports\Mom\MomReport::class,
    ],
];
