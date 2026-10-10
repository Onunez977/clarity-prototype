<?php

namespace App\Clickhouse\Queries;

use Illuminate\Support\Str;

abstract class ClickhouseQuery
{
    abstract public static function handle(array $arguments = []): mixed;

    public static function query(array $arguments = [], bool $cache = false): mixed
    {
        try {
            if (! $cache) {
                return (\get_called_class())::handle($arguments);
            }

            $cacheTTL = $arguments['cacheTTL'] ?? now()->addMinutes(10);

            if (session()?->has('admin_impersonator') ?? false) {
                $cacheTTL = now()->addSeconds(10);
            }

            return cache()->remember(
                self::buildCacheKey($arguments),
                $cacheTTL,
                fn () => (\get_called_class())::handle($arguments)
            );
        } catch (\Throwable $e) {
            if (\in_array(config('app.env'), ['production', 'testing'])) {
                report($e);

                return [];
            }

            throw $e;
        }
    }

    protected static function buildCacheKey(array $arguments = []): string
    {
        $class = Str::of(\get_called_class())
            ->afterLast('\\')
            ->snake('-')
            ->toString();

        $keys = [$class];

        foreach ($arguments as $key => $value) {
            if ($key === 'cacheTTL') {
                continue;
            }

            // Underscore-prefixed keys carry payload too large for cache keys
            // (e.g. polygon coordinates); pair with a sibling scalar for invalidation.
            if (\str_starts_with($key, '_')) {
                continue;
            }

            if (\gettype($value) === 'array') {
                $sorted = $value;
                sort($sorted);
                $keys[] = $key.'='.implode('-', $sorted);

                continue;
            }

            $keys[] = $key.'='.\str_replace(' ', '_', (string) $value);
        }

        return \implode(':', $keys);
    }

    /**
     * Emit `[(lng,lat),(lng,lat),...]` for a single ring; each coord is
     * (float)-cast so the literal is injection-safe under whereRaw (the CH
     * driver mishandles parameter binding on array literals).
     *
     * @param  list<array{0: int|float, 1: int|float}>  $ring
     */
    protected static function polygonLiteral(array $ring): string
    {
        $pairs = [];

        foreach ($ring as $point) {
            $pairs[] = '('.self::floatOrZero($point[0]).','.self::floatOrZero($point[1]).')';
        }

        return '['.implode(',', $pairs).']';
    }

    private static function floatOrZero(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Database prefix for raw-SQL queries on the clarity connection.
     * Resolves to the configured CH database (`clarity` in prod, `clarity_<wt>`
     * under local-CH QA) — or the literal `clarity` schema under the pgsql bridge.
     */
    protected static function clarityDb(): string
    {
        return config('database.connections.clarity_clickhouse.driver') === 'pgsql'
            ? 'clarity'
            : config('database.connections.clarity_clickhouse.database');
    }

    /**
     * Database prefix for cross-database raw-SQL reads of the zod CDC tables
     * from the clarity connection. Resolves to the configured zod CH database
     * (`zod` in prod, `zod_<wt>` under local-CH QA) — or the literal `zod`
     * schema under the pgsql bridge.
     */
    protected static function zodDb(): string
    {
        return config('database.connections.clarity_clickhouse.driver') === 'pgsql'
            ? 'zod'
            : config('database.connections.public_clickhouse.database');
    }
}
