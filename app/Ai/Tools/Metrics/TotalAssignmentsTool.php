<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\TotalAssignmentsWidget;

class TotalAssignmentsTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return TotalAssignmentsWidget::class;
    }

    protected function clientAccepted(): bool
    {
        return false;
    }

    protected function periods(bool $forClient): array
    {
        return ['this_month', 'ytd'];
    }
}
