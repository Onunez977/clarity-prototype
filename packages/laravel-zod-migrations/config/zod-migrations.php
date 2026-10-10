<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public (shared Zod) connection
    |--------------------------------------------------------------------------
    |
    | Laravel connection name used for the shared public schema dump and any
    | incremental migrations in database/migrations/public.
    |
    */

    'public_connection' => env('ZOD_PUBLIC_CONNECTION', 'zod'),

    /*
    |--------------------------------------------------------------------------
    | Application schema connection
    |--------------------------------------------------------------------------
    |
    | Connection that owns app-specific tables (platform, overwatch, etc.).
    |
    */

    'app_connection' => env('ZOD_APP_CONNECTION', 'platform'),

    /*
    |--------------------------------------------------------------------------
    | Application schema name
    |--------------------------------------------------------------------------
    |
    | PostgreSQL schema created before app migrations run.
    |
    */

    'app_schema' => env('ZOD_APP_SCHEMA', 'platform'),

    /*
    |--------------------------------------------------------------------------
    | Application schema strategy
    |--------------------------------------------------------------------------
    |
    | Controls how the application schema is built during migrate:fresh after
    | the public dump has been loaded:
    |
    |   'fresh'   - wipe the app schema and rebuild it from its full migration
    |               set. Suits apps whose migrations are the source of truth
    |               (e.g. Platform). This is the default.
    |   'migrate' - apply only pending migrations on top of the app schema that
    |               already ships inside the public dump. Suits squash-dump +
    |               delta apps such as Guardian/Overwatch, where wiping would
    |               also drop shared public/extension-owned tables.
    |
    */

    'app_strategy' => env('ZOD_APP_STRATEGY', 'fresh'),

    /*
    |--------------------------------------------------------------------------
    | Schema dump paths
    |--------------------------------------------------------------------------
    |
    | Override to point at a custom SQL dump. When null, the package-bundled
    | schema files are used.
    |
    */

    'zod_schema_path' => env('ZOD_SCHEMA_PATH'),

    'testing_schema_path' => env('ZOD_TESTING_SCHEMA_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Public migrations path
    |--------------------------------------------------------------------------
    |
    | Incremental migrations applied on top of the public schema dump.
    |
    */

    'public_migrations_path' => env('ZOD_PUBLIC_MIGRATIONS_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Post-public-migrate SQL
    |--------------------------------------------------------------------------
    |
    | Optional raw SQL statements executed after the public schema is loaded.
    | Apps can publish this config to register views or other objects that are
    | not part of the canonical dump.
    |
    */

    'post_public_migrate_sql' => [
        //
    ],

];
