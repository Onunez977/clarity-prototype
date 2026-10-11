<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\RecoveryLeader;

class RecoveryLeaderTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return RecoveryLeader::class;
    }
}
