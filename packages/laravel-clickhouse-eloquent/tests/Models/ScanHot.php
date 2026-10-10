<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;

class ScanHot extends Model
{
    protected $table = 'guardian.scans_hot';
    protected $connection = 'clickhouse-cloud';
}
