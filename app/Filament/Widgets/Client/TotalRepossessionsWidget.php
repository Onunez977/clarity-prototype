<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class TotalRepossessionsWidget extends ClientStatWidget
{
    protected function getLabel(): string
    {
        return 'Total Repossessions';
    }

    protected function getDrillDownKey(): ?string
    {
        return 'totalRepossessions';
    }

    protected function getBatchCacheKey(): ?string
    {
        return 'repos';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return (int) Clickhouse\HotlistStatusSource::query($this->branch_id)
            ->forOrg($this->org_id)
            ->between($startDate, $endDate, $this->getTimezone())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id)
            ->repossessions()
            ->selectRaw(Clickhouse\HotlistStatusSource::totalExpression($this->branch_id))
            ->value('total') ?? 0;
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Total recorded repossessions during the selected time range.';
    }
}
