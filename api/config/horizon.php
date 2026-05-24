<?php

declare(strict_types=1);

/**
 * Horizon configuration. Queue layout per spec §12.2 / docs/06-sync.
 *
 *   default:       4 workers   — light operations, callbacks
 *   shopify-sync:  8 workers   — Shopify GraphQL tolerates higher concurrency
 *   ads-sync:      4 workers   — Meta / Google / TikTok share this queue
 *   aggregation:   2 workers   — Phase 2 ad_performance / product_performance
 *
 *   Job timeouts:  10 minutes per job
 *   Retries:       3 attempts with 1m / 5m / 15m backoff
 *   Memory:        256 MB per worker
 */
return [
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    'use'    => 'default',
    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:default'      => 60,
        'redis:shopify-sync' => 120,
        'redis:ads-sync'     => 120,
        'redis:aggregation'  => 120,
    ],

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 256,

    'defaults' => [
        'supervisor-default' => [
            'connection'           => 'redis',
            'queue'                => ['default'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'maxProcesses'         => 4,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 3,
            'timeout'              => 600,
            'nice'                 => 0,
        ],
        'supervisor-shopify' => [
            'connection'           => 'redis',
            'queue'                => ['shopify-sync'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'maxProcesses'         => 8,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 3,
            'timeout'              => 600,
            'nice'                 => 0,
        ],
        'supervisor-ads' => [
            'connection'           => 'redis',
            'queue'                => ['ads-sync'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'maxProcesses'         => 4,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 3,
            'timeout'              => 600,
            'nice'                 => 0,
        ],
        'supervisor-aggregation' => [
            'connection'           => 'redis',
            'queue'                => ['aggregation'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'maxProcesses'         => 2,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 3,
            'timeout'              => 600,
            'nice'                 => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default'     => ['maxProcesses' => 4,  'balanceMaxShift' => 1, 'balanceCooldown' => 3],
            'supervisor-shopify'     => ['maxProcesses' => 8,  'balanceMaxShift' => 1, 'balanceCooldown' => 3],
            'supervisor-ads'         => ['maxProcesses' => 4,  'balanceMaxShift' => 1, 'balanceCooldown' => 3],
            'supervisor-aggregation' => ['maxProcesses' => 2,  'balanceMaxShift' => 1, 'balanceCooldown' => 3],
        ],
        'local' => [
            'supervisor-default'     => ['maxProcesses' => 2],
            'supervisor-shopify'     => ['maxProcesses' => 2],
            'supervisor-ads'         => ['maxProcesses' => 2],
            'supervisor-aggregation' => ['maxProcesses' => 1],
        ],
    ],
];
