<?php

namespace App\Clickhouse\Concerns;

trait HasMonthGrouping
{
    protected function getMonthSelectSql(string $column): string
    {
        return "toDate(toStartOfMonth({$column})) as month";
    }

    public static function getMonthGroupBySql(string $column): string
    {
        return "toStartOfMonth({$column})";
    }

    public static function getMonthSelectExpression(string $column): string
    {
        return "toDate(toStartOfMonth({$column})) as month";
    }
}
