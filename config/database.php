<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'platform'),

    /*
    |--------------------------------------------------------------------------
    | Use ClickHouse for Analytics
    |--------------------------------------------------------------------------
    |
    | Determines whether to use ClickHouse for dashboard widget queries.
    | Automatically enabled if CLICKHOUSE_HOST is configured.
    | Tests should not set CLICKHOUSE_HOST to ensure PostgreSQL is used.
    |
    */

    'use_clickhouse' => ! empty(env('CLICKHOUSE_HOST')),

    'track_analytics' => (bool) env('TRACK_ANALYTICS', false),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],
        'zod' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'read' => [
                'host' => [
                    env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_HOST', '127.0.0.1'),
                ],
            ],
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'zod'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
        'platform_read' => [
            [
                'driver' => 'pgsql',
                'url' => env('DATABASE_URL'),
                'host' => env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
                'sticky' => env('DB_STICKY', true),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'zod'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'platform,public',
                'sslmode' => 'prefer',
            ],
        ],
        'platform' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'read' => [
                'host' => [
                    env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_HOST', '127.0.0.1'),
                ],
            ],
            'sticky' => env('DB_STICKY', true),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'zod'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SEARCH_PATH', 'platform'),
            'sslmode' => 'prefer',
        ],
        'graphql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'read' => [
                'host' => [
                    env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
                ],
            ],
            'write' => [
                'host' => [
                    env('DB_HOST', '127.0.0.1'),
                ],
            ],
            'sticky' => env('DB_STICKY', true),
            'port' => env('DB_PORT', '5432'),
            'database' => 'graphql',
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix_indexes' => true,
            'search_path' => 'platform',
            'sslmode' => 'prefer',
        ],
        'pg' => [
            'driver' => 'pgsql',
            'host' => env('DB_WRITE_HOST', env('DB_HOST', '127.0.0.1')),
            'sticky' => env('DB_STICKY', true),
            'url' => env('DATABASE_URL'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'zod'),
            'username' => env('PG_USERNAME', env('DB_USERNAME', 'pg')),
            'password' => env('PG_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
        'clarity_clickhouse' => [
            'driver' => env('CLICKHOUSE_DRIVER', 'clickhouse'),
            'host' => env('CLICKHOUSE_HOST'),
            'port' => env('CLICKHOUSE_PORT', '8123'),
            'database' => env('CLICKHOUSE_DATABASE_CLARITY', 'clarity'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            // Options: ClickHouse uses protocol option, PostgreSQL uses PDO fetch mode for array returns
            'options' => env('CLICKHOUSE_DRIVER', 'clickhouse') === 'pgsql'
                ? [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                : ['protocol' => env('CLICKHOUSE_HTTPS', true) ? 'https' : 'http'],
            'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
            'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 60),
            'https' => (bool) env('CLICKHOUSE_HTTPS', true),
            'retries' => env('CLICKHOUSE_RETRIES', 0),
            'settings' => [
                'max_bytes_before_external_group_by' => 10000000000, // 10 GB — spill GROUP BY to disk before hitting server memory limit
                'validate_polygons' => 0, // run-area clip (HotlistAddressGeohashQuery) feeds PostGIS-valid but non-simple rings to pointInPolygon, which would otherwise throw
            ],
            'fix_default_query_builder' => true,
            // PostgreSQL-specific options (used when driver is pgsql for testing)
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // Use clarity schema for PostgreSQL testing, public for ClickHouse (ignored)
            'search_path' => env('CLICKHOUSE_DRIVER', 'clickhouse') === 'pgsql' ? 'clarity,public' : 'public',
            'sslmode' => 'prefer',
        ],
        'clarity_clickhouse_write' => [
            'driver' => env('CLICKHOUSE_DRIVER', 'clickhouse'),
            'host' => env('CLICKHOUSE_HOST'),
            'port' => env('CLICKHOUSE_PORT', '8123'),
            'database' => env('CLICKHOUSE_DATABASE_CLARITY', 'clarity'),
            'username' => env('CLICKHOUSE_WRITE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_WRITE_PASSWORD', ''),
            'options' => env('CLICKHOUSE_DRIVER', 'clickhouse') === 'pgsql'
                ? [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                : ['protocol' => env('CLICKHOUSE_HTTPS', true) ? 'https' : 'http'],
            // Write path gets a longer connect budget + one in-client retry: ClickHouse Cloud
            // TLS handshake / idle-scaling wake intermittently exceeds the 2s read-path budget,
            // timing out analytics inserts (DEV-2123). Read paths keep the 2s fast-fail.
            'timeout_connect' => env('CLICKHOUSE_WRITE_TIMEOUT_CONNECT', 5),
            'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 60),
            'https' => (bool) env('CLICKHOUSE_HTTPS', true),
            'retries' => env('CLICKHOUSE_WRITE_RETRIES', 1),
            'settings' => [],
            'fix_default_query_builder' => true,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('CLICKHOUSE_DRIVER', 'clickhouse') === 'pgsql' ? 'clarity,public' : 'public',
            'sslmode' => 'prefer',
        ],
        'public_clickhouse' => [
            'driver' => env('CLICKHOUSE_DRIVER', 'clickhouse'),
            'host' => env('CLICKHOUSE_HOST'),
            'port' => env('CLICKHOUSE_PORT', '8123'),
            'database' => env('CLICKHOUSE_DATABASE_ZOD', 'zod'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
            // Options: ClickHouse uses protocol option, PostgreSQL uses PDO fetch mode for array returns
            'options' => env('CLICKHOUSE_DRIVER', 'clickhouse') === 'pgsql'
                ? [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                : ['protocol' => env('CLICKHOUSE_HTTPS', true) ? 'https' : 'http'],
            'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
            'timeout_query' => env('CLICKHOUSE_TIMEOUT_QUERY', 60),
            'https' => (bool) env('CLICKHOUSE_HTTPS', true),
            'retries' => env('CLICKHOUSE_RETRIES', 0),
            'settings' => [
                'max_execution_time' => env('CLICKHOUSE_TIMEOUT_QUERY', 60),
            ],
            'fix_default_query_builder' => true,
            // PostgreSQL-specific options (used when driver is pgsql for testing)
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => array_merge([
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'platform'), '_').'_database_'),
        ],

        'clusters' => [
            'default' => [
                [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST_1', '127.0.0.1'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'port' => env('REDIS_PORT', '6379'),
                    'database' => env('REDIS_DB', '0'),
                ],
                [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST_2', '127.0.0.1'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'port' => env('REDIS_PORT', '6379'),
                    'database' => env('REDIS_DB', '0'),
                ],
            ],
        ],
    ], env('REDIS_STANDALONE') ? [
        // Local Redis (lerd/Herd). Laravel prefers this over clusters.default.
        // Leave REDIS_STANDALONE unset in Kamal/production.
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ] : []),

];
