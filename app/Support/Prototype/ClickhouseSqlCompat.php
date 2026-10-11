<?php

declare(strict_types=1);

namespace App\Support\Prototype;

/**
 * Rewrites the ClickHouse SQL Company Metrics and Scout send when the
 * prototype is using Postgres (CLICKHOUSE_DRIVER=pgsql) as a stand-in.
 */
class ClickhouseSqlCompat
{
    public static function rewrite(string $sql): string
    {
        $sql = self::replaceCall($sql, 'countDistinctIf', function (array $args): string {
            return 'count(DISTINCT '.$args[0].') FILTER (WHERE '.$args[1].')';
        });
        $sql = self::replaceCall($sql, 'uniqExactIf', function (array $args): string {
            return 'count(DISTINCT '.$args[0].') FILTER (WHERE '.$args[1].')';
        });
        $sql = self::replaceCall($sql, 'countIf', function (array $args): string {
            return 'count(*) FILTER (WHERE '.$args[0].')';
        });
        $sql = self::replaceCall($sql, 'uniqExact', function (array $args): string {
            return 'count(DISTINCT '.$args[0].')';
        });
        $sql = self::replaceCall($sql, 'countDistinct', function (array $args): string {
            return 'count(DISTINCT '.$args[0].')';
        });
        $sql = self::replaceCall($sql, 'anyLast', function (array $args): string {
            return 'max('.$args[0].')';
        });
        $sql = self::replaceCall($sql, 'toString', function (array $args): string {
            return '('.$args[0].')::text';
        });
        $sql = self::replaceCall($sql, 'toFloat64', function (array $args): string {
            return '('.$args[0].')::double precision';
        });
        $sql = self::replaceCall($sql, 'if', function (array $args): string {
            return 'CASE WHEN '.$args[0].' THEN '.$args[1].' ELSE '.$args[2].' END';
        });

        $sql = (string) preg_replace('/\bcount\(\s*\)/', 'count(*)', $sql);
        $sql = (string) preg_replace('/\s+FINAL\b/i', '', $sql);

        return (string) preg_replace('/\bGROUP BY\s+(.+?)\s+WITH ROLLUP\b/i', 'GROUP BY ROLLUP($1)', $sql);
    }

    /**
     * @param  callable(array<int, string>): string  $replace
     */
    private static function replaceCall(string $sql, string $name, callable $replace): string
    {
        $pattern = '/\b'.preg_quote($name, '/').'\s*\(/i';
        $offset = 0;
        while (preg_match($pattern, $sql, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $open = strpos($sql, '(', $start);
            if ($open === false) {
                break;
            }
            $args = self::splitArgs($sql, $open);
            if ($args === null) {
                break;
            }
            [$pieces, $end] = $args;
            $sql = substr($sql, 0, $start).$replace($pieces).substr($sql, $end);
            $offset = $start + 1;
        }

        return $sql;
    }

    /**
     * @return array{0: array<int, string>, 1: int}|null
     */
    private static function splitArgs(string $sql, int $openParen): ?array
    {
        $depth = 0;
        $args = [];
        $current = '';
        $length = strlen($sql);
        for ($i = $openParen; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === '(') {
                $depth++;
                if ($depth > 1) {
                    $current .= $char;
                }

                continue;
            }
            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $args[] = trim($current);

                    return [$args, $i + 1];
                }
                $current .= $char;

                continue;
            }
            if ($char === ',' && $depth === 1) {
                $args[] = trim($current);
                $current = '';

                continue;
            }
            if ($depth >= 1) {
                $current .= $char;
            }
        }

        return null;
    }
}
