<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\StagingLiveHitLeader;

class StagingLiveHitLeaderTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return StagingLiveHitLeader::class;
    }
}
