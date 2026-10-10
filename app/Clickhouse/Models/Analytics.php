<?php

namespace App\Clickhouse\Models;

use LaravelClickhouseEloquent\BaseModel;

class Analytics extends BaseModel
{
    protected $table = 'analytics';

    protected $connection = 'clarity_clickhouse_write';
}
