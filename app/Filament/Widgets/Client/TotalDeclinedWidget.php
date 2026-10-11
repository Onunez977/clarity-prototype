<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Queries\Client\CohortDeclinedQuery;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class TotalDeclinedWidget extends ClientStatWidget
{
    protected function getDrillDownKey(): ?string
    {
        return 'totalDeclined';
    }

    protected function getLabel(): string
    {
        return 'Total Declined';
    }

    protected function getBatchCacheKey(): ?string
    {
        return 'declined';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return CohortDeclinedQuery::total(
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
        return 'Assignments opened and declined during the selected time range.';
    }
}
