<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class TotalAssignmentsWidget extends ClientStatWidget
{
    protected function getLabel(): string
    {
        return 'Total Assignments';
    }

    protected function getDrillDownKey(): ?string
    {
        return 'totalAssignments';
    }

    protected function getBatchCacheKey(): ?string
    {
        return 'assignments';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return (int) Clickhouse\HotlistStatusSource::query($this->branch_id)
            ->forOrg($this->org_id)
            ->between($startDate, $endDate, $this->getTimezone())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id)
            ->openAssignments()
            ->selectRaw(Clickhouse\HotlistStatusSource::totalExpression($this->branch_id))
            ->value('total') ?? 0;
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Total accepted assignments received during the selected time range.';
    }
}
