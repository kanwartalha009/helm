<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Database configuration
|--------------------------------------------------------------------------
|
| Helm's spec locks Postgres 16. Cloudways doesn't natively offer Postgres,
| so production has been switched to MySQL/MariaDB to avoid adding a
| third-party managed-Postgres dependency on a tight deadline. Local dev
| can still run either by flipping DB_CONNECTION in .env.
|
| Schema changes that landed as part of the switch:
|   - Every JSONB column became JSON. Laravel's `array` and `encrypted:array`
|     casts continue to work unchanged.
|   - timestampTz / timestampsTz became plain timestamp / timestamps. Carbon
|     normalizes to UTC at write time, so timezone-aware reads still work.
|   - The `platform_credentials` partial unique index (status='active') is
|     now enforced in the PlatformCredential model (saving event) — MySQL
|     does not support WHERE-filtered indexes.
|
*/

return [

    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [

        'mysql' => [
            'driver'         => 'mysql',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'helm'),
            'username'       => env('DB_USERNAME', 'helm'),
            'password'       => env('DB_PASSWORD', ''),
            'unix_socket'    => env('DB_SOCKET', ''),
            'charset'        => env('DB_CHARSET', 'utf8mb4'),
            'collation'      => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => 'InnoDB',
            // Cloudways MariaDB ships with the SSL bundle in place; honour
            // DB_SSL=true so production turns it on without code changes.
            'options'        => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'helm'),
            'username'       => env('DB_USERNAME', 'helm'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => env('DB_CHARSET', 'utf8'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DB_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix'  => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_database_'),
        ],

        /*
         * `timeout` / `read_timeout` are set EXPLICITLY (2026-07-13). Without them phpredis
         * inherits its defaults and a dropped or idle connection surfaces as:
         *
         *     PhpRedisConnector.php line 115: read error on connection to 127.0.0.1:6379
         *
         * — which is what killed a `sync:daily` mid-dispatch. `block_for` is null in
         * config/queue.php, so workers POLL rather than issue long blocking pops; a 60s read
         * timeout therefore cannot cut a legitimate BLPOP short. It only bounds a connection
         * that has actually gone away, so the client fails fast and reconnects instead of
         * hanging on a dead socket.
         */
        'default' => [
            'url'          => env('REDIS_URL'),
            'host'         => env('REDIS_HOST', '127.0.0.1'),
            'username'     => env('REDIS_USERNAME'),
            'password'     => env('REDIS_PASSWORD'),
            'port'         => env('REDIS_PORT', '6379'),
            'database'     => env('REDIS_DB', '0'),
            'timeout'      => (float) env('REDIS_TIMEOUT', 5),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 60),
        ],

        'cache' => [
            'url'          => env('REDIS_URL'),
            'host'         => env('REDIS_HOST', '127.0.0.1'),
            'username'     => env('REDIS_USERNAME'),
            'password'     => env('REDIS_PASSWORD'),
            'port'         => env('REDIS_PORT', '6379'),
            'database'     => env('REDIS_CACHE_DB', '1'),
            'timeout'      => (float) env('REDIS_TIMEOUT', 5),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 60),
        ],

    ],

];
