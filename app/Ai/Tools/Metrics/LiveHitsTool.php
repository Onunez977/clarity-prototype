<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\LiveHits;

class LiveHitsTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return LiveHits::class;
    }
}
