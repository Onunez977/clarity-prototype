<?php

namespace App\Clickhouse\Models;

use App\Clickhouse\Concerns\FiltersCdcDeletes;
use App\Clickhouse\Scopes\ApplyFinal;
use Carbon\Carbon;
use Database\Factories\Clickhouse\RecoveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Recovery extends ClickhouseModel
{
    use FiltersCdcDeletes;
    use HasFactory;

    protected $table = 'clarity.recoveries';

    protected $guarded = [];

    protected static function newFactory(): RecoveryFactory
    {
        return RecoveryFactory::new();
    }

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
}
