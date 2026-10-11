<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\RecoveryCounts;

class RecoveryCountsTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return RecoveryCounts::class;
    }
}
