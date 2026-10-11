<?php

namespace App\Clickhouse\Views;

use App\Clickhouse\Models\ClickhouseModel;

class ScanReport extends ClickhouseModel
{
    protected $table = 'scan_report';

    protected $primaryKey = 'kit_id';

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }
}
