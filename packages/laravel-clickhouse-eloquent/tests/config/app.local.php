<?php

/*
|--------------------------------------------------------------------------
| Minimal app config for running tests locally (without Docker).
|
| The standard app.php references App\Providers\* which only exist inside
| the full Laravel app created by the Docker bootstrap.
|--------------------------------------------------------------------------
*/

return [
    'name' => 'ClickHouseEloquentTest',
    'env' => 'testing',
    'debug' => true,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => env('APP_KEY', 'base64:dGVzdGluZ2tleXRoYXRpczMyYnl0ZXNsbw=='),
    'cipher' => 'AES-256-CBC',

    'providers' => [
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Events\EventServiceProvider::class,

        \LaravelClickhouseEloquent\ClickhouseServiceProvider::class,
    ],

    'aliases' => [],
];
