<?php

namespace App\Clickhouse\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class FilterDeletedRecords implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereRaw('_peerdb_is_deleted = 0');
    }
}
