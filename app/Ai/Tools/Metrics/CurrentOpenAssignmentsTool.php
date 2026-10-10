<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Client\CurrentOpenAssignmentsWidget;

class CurrentOpenAssignmentsTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return CurrentOpenAssignmentsWidget::class;
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
        return 'This is the current snapshot.';
    }
}
