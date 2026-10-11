<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class CurrentOpenAssignmentsWidget extends ClientStatWidget
{
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getCacheTTL(int $defaultMinutes = 30): \DateTimeInterface|\DateInterval|int
    {
        return parent::getCacheTTL($defaultMinutes);
    }

    protected function getLabel(): string
    {
        return 'Current Open Assignments';
    }

    protected function getModelQuery(): int
    {
        return (int) Clickhouse\ZodPublicHotlist::query()
            ->forOrg($this->org_id)
            ->forClient($this->client_org_id)
            ->open()
            ->count();
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Current count of open assignments for this client excluding holds.';
    }

    protected function getStatClass(): ?string
    {
        return 'highlighted-widget';
    }
}
