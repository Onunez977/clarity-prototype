<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\ThirtyDayRecoveryRateWidget;

class ThirtyDayRecoveryRateTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return ThirtyDayRecoveryRateWidget::class;
    }

    protected function clientRequired(): bool
    {
        return true;
    }

    protected function periods(bool $forClient): array
    {
        return [];
    }

    protected function note(): ?string
    {
        return 'This rate always uses the last 30 days.';
    }
}
