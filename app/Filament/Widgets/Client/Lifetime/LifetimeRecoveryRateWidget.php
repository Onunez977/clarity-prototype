<?php

namespace App\Filament\Widgets\Client\Lifetime;

use App\Clickhouse\Models\HotlistStatusSource;
use App\Filament\Widgets\Client\Concerns\LifetimeStatWidget;

class LifetimeRecoveryRateWidget extends LifetimeStatWidget
{
    protected function getLabel(): string
    {
        return 'Lifetime Recovery Rate';
    }

    protected function getModelQuery(): string
    {
        $baseQuery = fn () => HotlistStatusSource::query($this->branch_id)
            ->forOrg($this->org_id)
            ->between($this->getStartDate(), $this->getEndDate())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id);

        $assignmentsCount = (int) $baseQuery()
            ->openAssignments()
            ->selectRaw(HotlistStatusSource::totalExpression($this->branch_id))
            ->value('total') ?? 0;

        $repoCount = (int) $baseQuery()
            ->repossessions()
            ->selectRaw(HotlistStatusSource::totalExpression($this->branch_id))
            ->value('total') ?? 0;

        $value = $assignmentsCount > 0
            ? (int) round(($repoCount / $assignmentsCount) * 100)
            : 0;

        return $value.'%';
    }

    protected function getTooltipDescription(): ?string
    {
        return 'All-time recovery rate (repossessions ÷ total assignments × 100) across all clients.';
    }
}
