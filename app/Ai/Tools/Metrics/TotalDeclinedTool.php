<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\TotalDeclinedWidget;

class TotalDeclinedTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return TotalDeclinedWidget::class;
    }

    protected function clientRequired(): bool
    {
        return true;
    }
}
