<?php

namespace App\Clickhouse\Models;

use Database\Factories\Clickhouse\HitStatsDailyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HitStatsDaily extends ClickhouseModel
{
    use HasFactory;

    protected $table = 'clarity.hit_stats_daily';

    protected $guarded = [];

    protected static function newFactory(): HitStatsDailyFactory
    {
        return HitStatsDailyFactory::new();
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeBetween($query, $start, $end)
    {
        return $query
            ->where('date', '>=', $start)
            ->where('date', '<=', $end);
    }
}
