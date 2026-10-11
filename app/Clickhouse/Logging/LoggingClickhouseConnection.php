<?php

declare(strict_types=1);

namespace App\Clickhouse\Logging;

use LaravelClickhouseEloquent\Connection;

/**
 * Installs the logging HTTP client. The package factory assigns a stock client after new static.
 */
class LoggingClickhouseConnection extends Connection
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function createWithClient(array $config): static
    {
        $connection = new static(null, $config['database'], '', $config);
        $connection->client = new LoggingClickhouseHttpClient($config);

        return $connection;
    }
}
