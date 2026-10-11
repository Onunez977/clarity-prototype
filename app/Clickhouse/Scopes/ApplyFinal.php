<?php

namespace App\Clickhouse\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ApplyFinal implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $baseQuery = $builder->getQuery();

        if (method_exists($baseQuery, 'final')) {
            $baseQuery->final();
        }
    }
}
