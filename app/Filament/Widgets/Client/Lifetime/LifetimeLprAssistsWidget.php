<?php

namespace App\Filament\Widgets\Client\Lifetime;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\LifetimeStatWidget;

class LifetimeLprAssistsWidget extends LifetimeStatWidget
{
    protected function getLabel(): string
    {
        return 'Lifetime LPR Assists';
    }

    protected function getModelQuery(): int
    {
        return Clickhouse\LprAssist::getTotal(
            orgId: $this->org_id ?? auth()->user()->org_id,
            start: $this->getStartDate(),
            end: $this->getEndDate(),
            timezone: auth()->user()?->timezone ?? 'UTC',
            branchId: $this->branch_id,
            clientOrgId: $this->client_org_id,
        );
    }

    protected function getTooltipDescription(): ?string
    {
        return 'All-time LPR assists across all clients.';
    }
}
