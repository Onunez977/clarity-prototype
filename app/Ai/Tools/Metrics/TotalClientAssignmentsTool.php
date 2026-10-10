<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\TotalClientAssignmentsWidget;

class TotalClientAssignmentsTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return TotalClientAssignmentsWidget::class;
    }

    protected function clientRequired(): bool
    {
        return true;
    }
}
