<?php

declare(strict_types=1);

namespace App\Clickhouse\Logging;

use Illuminate\Support\Facades\Log;
use LaravelClickhouseEloquent\ClickhouseHttpClient;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;

/**
 * Logs ClickHouse HTTP failures with the SQL and caller, then rethrows the same exception.
 *
 * The package client throws from private request() with a parsed message only. select() and
 * write() still have the statement. insert() reaches write(), so these two overrides cover
 * every HTTP failure. Timeout budgets and the SQL sent to ClickHouse stay unchanged.
 */
class LoggingClickhouseHttpClient extends ClickhouseHttpClient
{
    public const SQL_LIMIT = 4096;

    public const BINDING_ITEM_LIMIT = 32;

    public const BINDING_STRING_LIMIT = 200;

    private const CREDENTIAL_ASSIGNMENT = '/(?:password|secret|token|api_key|api-key|authorization)\s*[:=]\s*\S+/i';

    private const BEARER = '/bearer\s+\S+/i';

    private readonly string $connectionName;

    private readonly int $timeoutQuery;

    private readonly int $timeoutConnect;

    private readonly int $retries;

    private readonly ?int $settingsMaxExecutionTime;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];

        $this->connectionName = (string) ($config['name'] ?? '');
        $this->timeoutQuery = (int) ($config['timeout_query'] ?? 30);
        $this->timeoutConnect = (int) ($config['timeout_connect'] ?? 5);
        $this->retries = (int) ($config['retries'] ?? 0);
        $this->settingsMaxExecutionTime = array_key_exists('max_execution_time', $settings)
            ? (int) $settings['max_execution_time']
            : null;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $startedAt = hrtime(true);

        try {
            return parent::select($sql, $bindings);
        } catch (ClickhouseException $exception) {
            $this->reportHttpFailure('select', $sql, $bindings, $startedAt, $exception);

            throw $exception;
        }
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    public function write(string $sql, array $bindings = []): bool
    {
        $startedAt = hrtime(true);

        try {
            return parent::write($sql, $bindings);
        } catch (ClickhouseException $exception) {
            $this->reportHttpFailure('write', $sql, $bindings, $startedAt, $exception);

            throw $exception;
        }
    }

    /**
     * @return array{sql: string, sql_truncated: bool, sql_original_length: int}
     */
    public static function truncateSql(string $sql): array
    {
        $length = strlen($sql);

        if ($length <= self::SQL_LIMIT) {
            return [
                'sql' => $sql,
                'sql_truncated' => false,
                'sql_original_length' => $length,
            ];
        }

        $omitted = $length - self::SQL_LIMIT;

        return [
            'sql' => substr($sql, 0, self::SQL_LIMIT).'…[truncated '.$omitted.' chars]',
            'sql_truncated' => true,
            'sql_original_length' => $length,
        ];
    }

    public static function redactSql(string $sql): string
    {
        $redacted = preg_replace(self::CREDENTIAL_ASSIGNMENT, '[redacted]', $sql) ?? $sql;

        return preg_replace(self::BEARER, '[redacted]', $redacted) ?? $redacted;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array{bindings_redacted: list<mixed>, binding_count: int, bindings_truncated: bool}
     */
    public static function redactBindings(array $bindings): array
    {
        $truncated = count($bindings) > self::BINDING_ITEM_LIMIT;
        $redacted = [];

        foreach (array_slice(array_values($bindings), 0, self::BINDING_ITEM_LIMIT) as $value) {
            [$safe, $cut] = self::redactBindingValue($value);
            $truncated = $truncated || $cut;
            $redacted[] = $safe;
        }

        return [
            'bindings_redacted' => $redacted,
            'binding_count' => count($bindings),
            'bindings_truncated' => $truncated,
        ];
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function reportHttpFailure(string $statement, string $sql, array $bindings, int $startedAt, ClickhouseException $exception): void
    {
        try {
            $truncated = self::truncateSql(self::redactSql($sql));
            $bindingsRedacted = self::redactBindings($bindings);
            $caller = self::caller();
            $message = self::redactExceptionMessage($exception->getMessage());
            $request = self::requestContext();

            Log::channel('db-error')->error('ClickHouse HTTP exception', [
                'sql' => $truncated['sql'],
                'sql_truncated' => $truncated['sql_truncated'],
                'sql_original_length' => $truncated['sql_original_length'],
                'statement' => $statement,
                'bindings_redacted' => $bindingsRedacted['bindings_redacted'],
                'binding_count' => $bindingsRedacted['binding_count'],
                'bindings_truncated' => $bindingsRedacted['bindings_truncated'],
                'ch_code' => self::clickHouseCode($message),
                'exception_message' => $message,
                'caller_class' => $caller['class'],
                'caller_function' => $caller['function'],
                'caller_file' => $caller['file'],
                'caller_line' => $caller['line'],
                'route_name' => $request['route_name'],
                'route_uri' => $request['route_uri'],
                'console' => $request['console'],
                'user_id' => $request['user_id'],
                'org_id' => $request['org_id'],
                'connection' => $this->connectionName,
                'client_elapsed_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'server_elapsed_ms' => self::serverElapsedMs($message),
                'timeout_query' => $this->timeoutQuery,
                'timeout_connect' => $this->timeoutConnect,
                'retries' => $this->retries,
                'curl_timeout' => $this->timeoutQuery + $this->timeoutConnect,
                'max_execution_time' => $this->settingsMaxExecutionTime ?? $this->timeoutQuery,
            ]);
        } catch (\Throwable) {
            // A broken log channel must not hide the ClickHouse failure.
        }
    }

    /**
     * @return array{class: ?string, function: ?string, file: ?string, line: ?int}
     */
    private static function caller(): array
    {
        $empty = [
            'class' => null,
            'function' => null,
            'file' => null,
            'line' => null,
        ];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? null;

            if (! is_string($class) || ! str_starts_with($class, 'App\\')) {
                continue;
            }

            if (str_starts_with($class, 'App\\Clickhouse\\Logging\\')) {
                continue;
            }

            $file = $frame['file'] ?? null;

            return [
                'class' => $class,
                'function' => isset($frame['function']) ? (string) $frame['function'] : null,
                'file' => is_string($file) ? self::relativePath($file) : null,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
            ];
        }

        return $empty;
    }

    private static function relativePath(string $path): string
    {
        $root = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }

    /**
     * Curl names the connect target. The thrown exception stays intact; the log line does not.
     */
    private static function redactExceptionMessage(string $message): string
    {
        return preg_replace(
            '/\bto\s+\S+\s+port\s+\d+/i',
            'to [redacted] port [redacted]',
            $message,
        ) ?? $message;
    }

    private static function clickHouseCode(string $message): ?int
    {
        if (preg_match('/ClickHouse error (\d+)/', $message, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function serverElapsedMs(string $message): ?float
    {
        if (preg_match('/elapsed\s+([0-9.]+)\s*ms/i', $message, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * @return array{route_name: ?string, route_uri: ?string, console: bool, user_id: int|string|null, org_id: mixed}
     */
    private static function requestContext(): array
    {
        $console = app()->runningInConsole();
        $routeName = null;
        $routeUri = null;

        if (! $console && app()->bound('request')) {
            $request = request();
            $routeName = $request->route()?->getName();
            $routeUri = '/'.ltrim($request->path(), '/');
        }

        return [
            'route_name' => $routeName,
            'route_uri' => $routeUri,
            'console' => $console,
            'user_id' => auth()->id(),
            'org_id' => auth()->user()?->org_id,
        ];
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private static function redactBindingValue(mixed $value): array
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return [$value, false];
        }

        if ($value instanceof \DateTimeInterface) {
            return [$value->format('Y-m-d H:i:s'), false];
        }

        if (is_array($value)) {
            $truncated = false;
            $redacted = [];

            foreach ($value as $key => $item) {
                [$safe, $cut] = self::redactBindingValue($item);
                $truncated = $truncated || $cut;
                $redacted[$key] = $safe;
            }

            return [$redacted, $truncated];
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            $redacted = self::redactSql($value);
            $cut = strlen($redacted) > self::BINDING_STRING_LIMIT;

            return [$cut ? substr($redacted, 0, self::BINDING_STRING_LIMIT) : $redacted, $cut];
        }

        return ['['.get_debug_type($value).']', false];
    }
}
