<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'maps' => [
            'key' => env('GOOGLE_MAPS_API_KEY'),
        ],
    ],

    'geocoding' => [
        'nominatim' => (bool) env('GEOCODING_NOMINATIM', env('APP_ENV') === 'local'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * GetOrgHotlistCases enriches new RDN orders as they arrive, newest first, one SOAP
     * call per row. Measured over 14 days: RDN arrivals are 76,307 rows/day across 1,781
     * orgs (~43/org/day); the other integrations add 160,997/day and none of them can ever
     * populate branch_id. {low} is one Horizon process and a case-info round trip measures
     * ~500 ms, so ~120 jobs/min is what it drains. Restricting to RDN is what brings
     * arrivals inside the drain, which is why max_per_run is a safety ceiling on one org's
     * fan-out rather than a share of a fleet budget.
     *
     * A run is not pushed as a burst: GetOrgHotlistCases spreads its fan-out evenly across
     * run_interval_minutes, so daily arrivals arrive at ~53 jobs/min against that drain
     * instead of ~76k at once ahead of the transactional mail that shares the queue.
     */
    'rdn' => [
        'api_url' => env('RDN_API_URL', 'https://api.recoverydatabase.net/').env('RDN_API_VERSION', '2.0'),

        // The kill switch for case-info enrichment, and the only way to stop it without a
        // code revert: case_info_max_per_run and case_info_lookback_days are both floored
        // at 1, so no value of either disables the loop. Default true keeps shipped
        // behaviour with nothing set; RDN_CASE_INFO_ENABLED=false stops the fleet's ~76k
        // daily SOAP calls with an env change alone. "false" is resolved to a real bool by
        // phpdotenv and "0" is a falsy string; the cast is what stops either from reaching
        // config as a string, where "false" would read as ON. Scoped to case-info only:
        // GetOrgBranches and GetOrgRdnClients are unrelated and keep running.
        'case_info_enabled' => (bool) env('RDN_CASE_INFO_ENABLED', true),

        // Four days of daily runs is four selections, which is what gives a row the three
        // lookup attempts below - the window IS the retry budget, so a shorter one silently
        // seals rows on their first miss. It does not multiply volume: candidates exclude
        // anything already enriched or sealed, so the extra days carry only the unresolved
        // tail, not two more days of arrivals.
        'case_info_lookback_days' => (int) env('RDN_CASE_INFO_LOOKBACK_DAYS', 4),
        'case_info_run_interval_minutes' => (int) env('RDN_CASE_INFO_RUN_INTERVAL_MINUTES', 1440),
        'case_info_max_per_run' => (int) env('RDN_CASE_INFO_MAX_PER_RUN', 1000),
        'default_rate_limit' => (int) env('RDN_DEFAULT_RATE_LIMIT', 100),

        // Failed branch lookups a hotlist may accumulate before it stops being selected.
        // This is a ceiling on a ceiling: GetOrgHotlistCaseInfo::maxLookupAttempts() caps
        // it below what the lookback window can deliver, because a row that ages out after
        // N selections can never reach an Nth miss. At the values above a row is selected
        // four times, so all three attempts are reachable and the fourth selection is the
        // one the seal hands to a resolvable row. Raising this alone does nothing; widen
        // case_info_lookback_days too.
        'case_info_max_lookup_attempts' => (int) env('RDN_CASE_INFO_MAX_LOOKUP_ATTEMPTS', 3),
    ],

    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
    ],

    'convertapi' => [
        'secret' => env('CONVERTAPI_SECRET'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_NUMBER'),
    ],

    'clearplan' => [
        'queue_url' => env('CLEARPLAN_QUEUE_URL', 'https://sqs.us-east-2.amazonaws.com/336279217542/clearplan_hits.fifo'),
    ],

    'rdn_metrics' => [
        'queue_url' => env('RDN_METRICS_QUEUE_URL'),
        'aws_profile' => env('RDN_METRICS_AWS_PROFILE', 'insight_sqs_production'),
        'aws_region' => env('RDN_METRICS_AWS_REGION', 'us-east-2'),
    ],

    'matcher' => [
        'key' => env('MATCHER_API_KEY'),
    ],

    'is_running_frankenphp' => env('PHP_RUNTIME_FRANKENPHP', false),
    'datadog_forwarding' => env('DATADOG_FORWARDING', false),

];
