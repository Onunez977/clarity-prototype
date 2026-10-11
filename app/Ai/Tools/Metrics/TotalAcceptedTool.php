<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\TotalAcceptedWidget;

class TotalAcceptedTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return TotalAcceptedWidget::class;
    }

    protected function clientRequired(): bool
    {
        return true;
    }
}
