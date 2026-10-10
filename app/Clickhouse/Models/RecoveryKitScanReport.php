<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use App\Clickhouse\Scopes\ApplyFinal;
use Carbon\Carbon;

class RecoveryKitScanReport extends ClickhouseModel
{
    use FiltersCdcDeletes;

    protected $table = 'clarity.recovery_kit_scan_report';

    protected $primaryKey = 'recovery_id';

    protected static function booted(): void
    {
        static::addGlobalScope(new ApplyFinal);
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeBetween($query, $start, $end)
    {
        return $query
            ->where('repo_date', '>=', Carbon::parse($start)->toDateString())
            ->where('repo_date', '<=', Carbon::parse($end)->toDateString());
    }

    public function scopeForKits($query, array $kitIds)
    {
        if (! empty($kitIds)) {
            return $query->whereIntegerInRaw('kit_id', $kitIds);
        }

        return $query;
    }
}
