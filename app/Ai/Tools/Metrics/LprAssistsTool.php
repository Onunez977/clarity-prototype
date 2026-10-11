<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\LprAssistsWidget;

class LprAssistsTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return LprAssistsWidget::class;
    }

    protected function periods(bool $forClient): array
    {
        return $forClient ? ['this_month', 'ytd', 'last_30d', 'last_90d'] : ['this_month', 'ytd'];
    }
}
