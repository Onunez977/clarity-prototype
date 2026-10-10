<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $timeblock
 * @property int $kit_id
 * @property int $scan_count
 * @property int $id
 * @property string|null $scanned_at
 * @property-read Kit|null $kit
 * @property-read Collection<int, Org> $orgs
 * @property-read int|null $orgs_count
 *
 * @method static \Database\Factories\AggregateScanCountByHourFactory factory($count = null, $state = [])
 * @method static Builder<static>|AggregateScanCountByHour newModelQuery()
 * @method static Builder<static>|AggregateScanCountByHour newQuery()
 * @method static Builder<static>|AggregateScanCountByHour query()
 * @method static Builder<static>|AggregateScanCountByHour whereId($value)
 * @method static Builder<static>|AggregateScanCountByHour whereKitId($value)
 * @method static Builder<static>|AggregateScanCountByHour whereScanCount($value)
 * @method static Builder<static>|AggregateScanCountByHour whereScannedAt($value)
 * @method static Builder<static>|AggregateScanCountByHour whereTimeblock($value)
 *
 * @mixin \Eloquent
 */
class AggregateScanCountByHour extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    public $timestamps = false;

    protected $table = 'aggregate_scan_count_h';

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function orgs(): HasManyThrough
    {
        return $this->hasManyThrough(Org::class, Kit::class, 'id', 'id', 'kit_id', 'org_id');
    }

    public static function affiliateStatsReportQuery(Org $org, array $kit_ids, Carbon $start, Carbon $end): Builder
    {
        return self::query()->fromSub(sprintf("
            WITH aggregated_data as (
                SELECT name, aggregate_scan_count_h.kit_id, scanned_at, scan_count, org_id
                FROM kits
                JOIN aggregate_scan_count_h on kits.id = aggregate_scan_count_h.kit_id
                WHERE kits.org_id = %s
                %s
                AND scanned_at between '%s' and '%s'
            ),

            total_scans_agg as (SELECT kit_id, sum(scan_count) as total_scans FROM aggregated_data GROUP BY kit_id),

            hours_with_50_or_more_scans as (SELECT kit_id, count(*) as total_hours FROM aggregated_data WHERE scan_count >= 50 GROUP BY kit_id),

            unique_scans as (SELECT kit_id, sum(scan_count) as total_unique_scans FROM aggregate_scan_count_unq_h WHERE (timeblock BETWEEN %s) GROUP BY kit_id),

            days_active as (
                SELECT days.kit_id, count(days.count) as count FROM (
                    SELECT kit_id, count(date_trunc('day', scanned_at)) as count FROM aggregated_data
                    GROUP BY kit_id, date_trunc('day', scanned_at)) as days
                GROUP BY days.kit_id
            )

            SELECT
                aggregated_data.kit_id as id,
                name,
                total_scans_agg.total_scans as scan_total,
                hours_with_50_or_more_scans.total_hours,
                unique_scans.total_unique_scans as unique_scans,
                days_active.count as total_days
            FROM aggregated_data
            LEFT JOIN total_scans_agg on total_scans_agg.kit_id = aggregated_data.kit_id
            LEFT JOIN hours_with_50_or_more_scans on hours_with_50_or_more_scans.kit_id = aggregated_data.kit_id
            LEFT JOIN unique_scans on unique_scans.kit_id = aggregated_data.kit_id
            LEFT JOIN days_active on days_active.kit_id = aggregated_data.kit_id
            GROUP BY
                aggregated_data.kit_id,
                name,
                total_scans_agg.total_scans,
                hours_with_50_or_more_scans.total_hours,
                unique_scans.total_unique_scans,
                days_active.count
            ",
            $org->id,
            isset($kit_ids[0])
                ? 'AND kits.id in('.\implode(', ', $kit_ids).')'
                : '',
            $start->toAtomString(),
            $end->toAtomString(),
            timeblock($start).'::numeric AND '.timeblock($end).'::numeric',
        ), 'aggregate_scan_count_h');
    }
}
