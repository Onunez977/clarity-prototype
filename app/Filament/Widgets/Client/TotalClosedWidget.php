<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Queries\Client\CohortClosedQuery;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class TotalClosedWidget extends ClientStatWidget
{
    protected function getDrillDownKey(): ?string
    {
        return 'totalClosed';
    }

    protected function getLabel(): string
    {
        return 'Total Closed';
    }

    protected function getBatchCacheKey(): ?string
    {
        return 'closed';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return CohortClosedQuery::total(
            orgId: $this->org_id,
            start: $startDate,
            end: $endDate,
            timezone: $this->getTimezone(),
            branchId: $this->branch_id,
            clientOrgId: $this->client_org_id,
        );
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Assignments closed during the selected time range.';
    }
}
