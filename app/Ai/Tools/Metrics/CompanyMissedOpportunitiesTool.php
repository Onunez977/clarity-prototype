<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\MissedOpportunities;

class CompanyMissedOpportunitiesTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return MissedOpportunities::class;
    }
}
