<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Models\Concerns;

use Illuminate\Database\Migrations\Migration;

class SupervillainMigration extends Migration
{
    public function __construct()
    {
        $this->connection = 'zod';
        throw_if(app()->environment('production'), \Exception::class, 'Do not run zot migrations in production');
    }
}
