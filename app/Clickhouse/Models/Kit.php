<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use Database\Factories\Clickhouse\KitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Kit extends ClickhouseModel
{
    use FiltersCdcDeletes;
    use HasFactory;

    protected $table = 'clarity.kits';

    protected $guarded = [];

    protected static function newFactory(): KitFactory
    {
        return KitFactory::new();
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('org_id', $orgId);
    }
}
