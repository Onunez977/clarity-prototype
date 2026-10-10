<?php

namespace Tests\Models;

use LaravelClickhouseEloquent\BaseModel;

class Camera extends BaseModel
{
    protected $table = 'guardian.cameras';
    protected $connection = 'clickhouse-cloud';
}
