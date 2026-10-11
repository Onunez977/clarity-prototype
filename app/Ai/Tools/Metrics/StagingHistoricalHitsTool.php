<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\StagingHistoricalHits;

class StagingHistoricalHitsTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return StagingHistoricalHits::class;
    }
}
