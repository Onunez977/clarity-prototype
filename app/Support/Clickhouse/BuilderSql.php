<?php

namespace App\Support\Clickhouse;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class BuilderSql
{
    public static function of(EloquentBuilder|QueryBuilder $query): string
    {
        $base = self::base($query);

        return self::interpolate($base->toSql(), $base);
    }

    /**
     * Mirrors Illuminate\Database\Query\Builder::aggregate() step for step, so the emitted
     * string is what ->count() actually compiles rather than something merely equivalent.
     *
     * @param  array<int, string>  $columns
     */
    public static function countOf(EloquentBuilder|QueryBuilder $query, array $columns = ['*']): string
    {
        $base = self::base($query);

        $clone = $base->cloneWithout($base->unions || $base->havings ? [] : ['columns'])
            ->cloneWithoutBindings($base->unions || $base->havings ? [] : ['select']);

        $clone->aggregate = ['function' => 'count', 'columns' => $columns];

        if (empty($clone->groups)) {
            $clone->orders = null;
            $clone->bindings['order'] = [];
        }

        $clone->columns ??= $columns;

        return self::interpolate($clone->toSql(), $clone);
    }

    /**
     * toBase() applies global scopes, so FiltersCdcDeletes / ApplyFinal reach the emitted SQL.
     * It is an Eloquent method: a query builder is already its own base.
     */
    private static function base(EloquentBuilder|QueryBuilder $query): QueryBuilder
    {
        return $query instanceof EloquentBuilder ? $query->toBase() : $query;
    }

    private static function interpolate(string $sql, QueryBuilder $base): string
    {
        $connection = $base->getConnection();
        $bindings = $connection->prepareBindings($base->getBindings());

        if ($bindings === []) {
            return $sql;
        }

        // The ClickHouse driver interpolates with its own client; the pgsql QA/test bridge
        // has no client and falls back to Laravel's grammar substitution.
        if (method_exists($connection, 'getClient')) {
            return $connection->getClient()->bindParams($sql, $bindings);
        }

        return $connection->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $bindings);
    }
}
