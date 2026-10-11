<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Models\Concerns;

use Illuminate\Database\Migrations\Migration;

class MyFleetMigration extends Migration
{
    public function __construct()
    {
        $this->connection = 'myfleet';
        throw_if(app()->environment('production'), \Exception::class, 'Do not run fleet migrations in production');
    }
}
