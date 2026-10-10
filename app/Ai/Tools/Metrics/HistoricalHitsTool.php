<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\HistoricalHits;

class HistoricalHitsTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return HistoricalHits::class;
    }
}
