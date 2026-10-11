<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class TotalAcceptedWidget extends ClientStatWidget
{
    protected function getDrillDownKey(): ?string
    {
        return 'totalAccepted';
    }

    protected function getLabel(): string
    {
        return 'Total Accepted';
    }

    protected function getBatchCacheKey(): ?string
    {
        return 'accepted';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return (int) Clickhouse\HotlistStatusSource::query($this->branch_id)
            ->forOrg($this->org_id)
            ->between($startDate, $endDate, $this->getTimezone())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id)
            ->accepted()
            ->selectTotal()
            ->value('total') ?? 0;
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Assignments marked as accepted during the selected time range.';
    }
}
