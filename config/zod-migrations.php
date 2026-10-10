<?php

return [

    'public_connection' => env('ZOD_PUBLIC_CONNECTION', 'zod'),

    'app_connection' => env('ZOD_APP_CONNECTION', 'platform'),

    'app_schema' => env('ZOD_APP_SCHEMA', 'platform'),

    // The production dump's scans trigger reads tiger.state.simplified_geom,
    // which the dump does not create. Add the columns before seeding.
    'post_public_migrate_sql' => [
        'ALTER TABLE IF EXISTS tiger.state ADD COLUMN IF NOT EXISTS the_geom geometry',
        'ALTER TABLE IF EXISTS tiger.state ADD COLUMN IF NOT EXISTS simplified_geom geometry',
        'ALTER TABLE IF EXISTS tiger.state ADD COLUMN IF NOT EXISTS geom_4326 geometry GENERATED ALWAYS AS (public.st_transform(the_geom, 4326)) STORED',
    ],

];
