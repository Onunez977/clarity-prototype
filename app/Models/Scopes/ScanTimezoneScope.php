<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\Scan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ScanTimezoneScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Scan|Model $model): void
    {
        $builder->scanTimezone();
    }
}
