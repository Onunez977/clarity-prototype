<?php

namespace App\Filament\Widgets\Client\Lifetime;

use App\Clickhouse\Models\HotlistStatusSource;
use App\Filament\Widgets\Client\Concerns\LifetimeStatWidget;

class LifetimeTotalAssignmentsWidget extends LifetimeStatWidget
{
    protected function getLabel(): string
    {
        return 'Lifetime Assignments';
    }

    protected function getModelQuery(): int
    {
        return (int) HotlistStatusSource::query($this->branch_id)
            ->forOrg($this->org_id)
            ->between($this->getStartDate(), $this->getEndDate())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id)
            ->openAssignments()
            ->selectRaw(HotlistStatusSource::totalExpression($this->branch_id))
            ->value('total') ?? 0;
    }

    protected function getTooltipDescription(): ?string
    {
        return 'All-time total accepted assignments across all clients.';
    }
}
