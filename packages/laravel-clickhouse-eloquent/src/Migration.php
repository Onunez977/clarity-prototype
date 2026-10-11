<?php

declare(strict_types=1);

namespace LaravelClickhouseEloquent;

use Illuminate\Database\Migrations\Migration as BaseMigration;
use Symfony\Component\Console\Output\ConsoleOutput;

class Migration extends BaseMigration
{

    use WithClient;

    protected $connection = Connection::DEFAULT_NAME;

    /**
     * @param string $sql
     * @param array $bindings
     * @return bool
     */
    protected static function write(string $sql, array $bindings = []): bool
    {
        $instance = new static();
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
            if ($trace['function'] === 'pretend') {
                $name = static::class;
                (new ConsoleOutput())->writeln(
                    "<comment>Clickhouse</comment> <info>$name on connection $instance->connection:</info> $sql"
                );
                return true;
            }
        }
        return $instance->getThisClient()->write($sql, $bindings);
    }
}
