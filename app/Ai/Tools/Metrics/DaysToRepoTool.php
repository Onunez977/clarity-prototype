<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\DaysToRepoWidget;

class DaysToRepoTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return DaysToRepoWidget::class;
    }

    protected function clientRequired(): bool
    {
        return true;
    }
}
