<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class LprAssistsWidget extends ClientStatWidget
{
    protected function getLabel(): string
    {
        return 'LPR Assists';
    }

    protected function getDrillDownKey(): ?string
    {
        return 'lprAssists';
    }

    protected function getBatchCacheKey(): ?string
    {
        return 'lpr_assists';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return Clickhouse\LprAssist::getTotal(
            orgId: $this->org_id ?? auth()->user()->org_id,
            start: $startDate,
            end: $endDate,
            timezone: $this->getTimezone(),
            branchId: $this->branch_id,
            clientOrgId: $this->client_org_id,
        );
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Repossessions where LPR scanning assisted in locating the vehicle within 0.1 of a mile for the selected time range.';
    }
}
