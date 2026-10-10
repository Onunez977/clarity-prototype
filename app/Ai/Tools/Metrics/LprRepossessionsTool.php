<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\LprRepossessions;

class LprRepossessionsTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return LprRepossessions::class;
    }
}
