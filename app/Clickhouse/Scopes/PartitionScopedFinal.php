<?php

namespace App\Clickhouse\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class PartitionScopedFinal implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $baseQuery = $builder->getQuery();

        if ($baseQuery instanceof \LaravelClickhouseEloquent\Builder) {
            $baseQuery->settings([
                'do_not_merge_across_partitions_select_final' => 1,
                'optimize_read_in_order' => 1,
                'max_final_threads' => 4,
                'max_threads' => 4,
            ]);
        }
    }
}
