<?php

/*
|--------------------------------------------------------------------------
| Minimal Laravel Application Bootstrap (for local testing)
|--------------------------------------------------------------------------
|
| The Docker test setup creates a full Laravel app via `composer create-project`.
| This bootstrap provides just enough for running tests locally without Docker.
|
*/

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    Illuminate\Foundation\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    Illuminate\Foundation\Exceptions\Handler::class
);

// Use local config (no App\Providers) when running outside Docker
$configPath = dirname(__DIR__) . '/tests/config';
$app->useConfigPath($configPath);

// Swap app.php → app.local.php when the Docker app providers aren't available
$app->afterBootstrapping(
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    function ($app) use ($configPath) {
        if (!class_exists('App\Providers\AppServiceProvider')) {
            $localConfig = require $configPath . '/app.local.php';
            $app['config']->set('app', $localConfig);
        }
    }
);

return $app;
