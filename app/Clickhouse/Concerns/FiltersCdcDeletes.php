<?php

namespace App\Clickhouse\Concerns;

use App\Clickhouse\Scopes\FilterDeletedRecords;

trait FiltersCdcDeletes
{
    public static function bootFiltersCdcDeletes(): void
    {
        static::addGlobalScope(new FilterDeletedRecords);
    }

    public function scopeWithDeleted($query)
    {
        return $query->withoutGlobalScope(FilterDeletedRecords::class);
    }
}
