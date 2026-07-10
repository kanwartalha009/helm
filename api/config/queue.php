<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Queue connections (published 2026-07-10)
|--------------------------------------------------------------------------
| Published for ONE reason: the framework-default redis retry_after is 90
| seconds, while Horizon workers run jobs up to 3600s (backfills). With the
| default, any job running past 90s gets re-reserved by another worker and
| runs TWICE concurrently. retry_after must exceed the longest job timeout:
| 3700 > 3600 (horizon.php supervisor timeout) > 3500 (BackfillBrandDatasetJob
| $timeout). Cost: a job lost to a crashed worker waits ~1h to retry — rare,
| and every sync/backfill job here is idempotent (upserts).
*/

return [
    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 3700),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 3700),
            'block_for' => null,
            'after_commit' => false,
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],
];
