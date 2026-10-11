<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\UniqueLprCaseClaims;

class UniqueLprCaseClaimsTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return UniqueLprCaseClaims::class;
    }
}
