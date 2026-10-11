<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;

class HitHot extends Model
{
    protected $table = 'guardian.hits_hot';
    protected $connection = 'clickhouse-cloud';
}
