<?php

namespace App\Clickhouse\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Pick the status-metric source: aggregate when unfiltered, transitions when a branch
 * is selected. Branch-filtered numbers must be exact; unfiltered cards must not move.
 */
class HotlistStatusSource
{
    public static function usesTransitions(?int $branchId): bool
    {
        return $branchId !== null && $branchId !== 0;
    }

    /**
     * @return Builder<HotlistStatusTransition>|Builder<HotlistStatusAggregate>
     */
    public static function query(?int $branchId = null)
    {
        return self::usesTransitions($branchId)
            ? HotlistStatusTransition::query()
            : HotlistStatusAggregate::query();
    }

    public static function totalExpression(?int $branchId): string
    {
        return self::usesTransitions($branchId)
            ? 'uniqExact(hotlist_id) AS total'
            : 'uniqMerge(unique_hotlists) AS total';
    }
}
