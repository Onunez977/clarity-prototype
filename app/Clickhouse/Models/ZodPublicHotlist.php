<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use App\Enums\HotlistStatusEnum;
use Carbon\Carbon;

class ZodPublicHotlist extends ClickhouseModel
{
    use FiltersCdcDeletes;

    protected $connection = 'public_clickhouse';

    protected $table = 'public_hotlist';

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeForClient($query, int $orgId)
    {
        return $query->where('client_org_id', $orgId);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', HotlistStatusEnum::Open->value);
    }

    public function scopeHold($query)
    {
        return $query->where('status', HotlistStatusEnum::Hold->value);
    }

    public function scopeActive($query)
    {
        return $query->whereIntegerInRaw('status', [
            HotlistStatusEnum::Open->value,
            HotlistStatusEnum::Hold->value,
        ]);
    }

    public function scopeCreatedAfter($query, string $date)
    {
        return $query->where('created_at', '>=', $date);
    }

    public function scopeBetween($query, $start, $end, ?string $timezone = 'UTC')
    {
        return $query
            ->where('created_at', '>=', Carbon::parse($start, $timezone)->utc()->toDateTimeString())
            ->where('created_at', '<=', Carbon::parse($end, $timezone)->utc()->toDateTimeString());
    }
}
