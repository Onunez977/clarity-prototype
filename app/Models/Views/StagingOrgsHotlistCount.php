<?php

namespace App\Models\Views;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StagingOrgsHotlistCount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StagingOrgsHotlistCount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StagingOrgsHotlistCount query()
 *
 * @mixin \Eloquent
 *
 * @codeCoverageIgnore
 */
class StagingOrgsHotlistCount extends Model
{
    protected $connection = 'zod';

    public $timestamps = false;

    protected $table = 'staging_orgs_hotlist_count';

    public static function getCount(array $stagingOrgIds = []): int
    {
        try {
            return cache()->remember(
                'staging-org-hotlist-count:'.\implode(',', $stagingOrgIds),
                now()->endOfHour(),
                fn () => self::query()
                    ->whereIn('org_id', $stagingOrgIds)
                    ->sum('total')
            );
        } catch (\Exception $exception) {
            return 0;
        }
    }
}
