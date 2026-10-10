<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    'old_platform_url' => env('OLD_PLATFORM_URL'),

    /*
    |--------------------------------------------------------------------------
    | QA Force-Login Bypass
    |--------------------------------------------------------------------------
    |
    | Enables the GET /__qa/login/{id?} route, which logs in as the given user
    | ID (default 1) without going through the email-code flow. Intended for
    | local QA sites (e.g. qa.platform.test) where the seeded users have
    | unreachable email addresses. Defaults to false — must be explicitly
    | enabled in a local .env. The route is additionally gated on local env,
    | loopback IP, and a .test hostname; never enable this anywhere else.
    |
    */

    'dev_login_enabled' => env('DEV_LOGIN_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Scanner Portal Debug Map View
    |--------------------------------------------------------------------------
    |
    | Gates the per-hour scan-path debug coloring/labeling in
    | HistoricPathBuilder (DEV-2018). Also suffixes the scan-paths cache key so
    | the two modes never collide. Default false.
    |
    */

    'scanner_debug_map_enabled' => env('SCANNER_DEBUG_MAP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS on Incoming Requests
    |--------------------------------------------------------------------------
    |
    | When true, the app treats every request as HTTPS. Required for absolute
    | signed URLs to validate behind a TLS-terminating proxy that forwards
    | plain HTTP to PHP (see ForceHttpsRequest). Defaults to true because
    | AppServiceProvider already forces https for outbound URL generation.
    |
    */

    'force_https' => filter_var(env('FORCE_HTTPS', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'date_format' => 'M j, Y h:i A T',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'experimental' => [
        'clickhouse' => [
            'scans' => env('CLICKHOUSE_SCANS_ENABLED', false),
            'hits' => env('CLICKHOUSE_HITS_ENABLED', false),
        ],
        'clearplan' => env('CLICKHOUSE_CLEARPLAN_ENABLED', false),
        'clarity_scout' => env('CLARITY_SCOUT_ENABLED', true),
        'clarity_scout_trips' => env('CLARITY_SCOUT_TRIPS_ENABLED', false),
        'clarity_scout_teammate_overlay' => env('CLARITY_SCOUT_TEAMMATE_OVERLAY_ENABLED', true),
    ],

    'pro' => [
        'sales_email' => env('PRO_SALES_EMAIL'),
    ],

];
