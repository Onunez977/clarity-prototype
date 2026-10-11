<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class DaysToRepoWidget extends ClientStatWidget
{
    protected function getDrillDownKey(): ?string
    {
        return 'daysToRepo';
    }

    protected function getLabel(): string
    {
        return 'Days to Repo';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): float
    {
        $result = Clickhouse\DaysToRepoAggregate::query()
            ->forOrg($this->org_id)
            ->between($startDate, $endDate, $this->getTimezone())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id)
            ->selectRaw('SUM(sum_days_to_repo_all) as total_days, SUM(count_repos_all) as total_count')
            ->first();

        $total = $result->total_count;
        $days = $result->total_days;

        if ($total == 0) {
            return 0;
        }

        return \round($days / $total, 1);
    }

    protected function formatValue(mixed $rawValue): string
    {
        if ($rawValue > 0 && $rawValue < 1) {
            return '< 1';
        }

        return (string) $rawValue;
    }

    protected function isLowerBetter(): bool
    {
        return true;
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Average days from assignment to repossession.';
    }
}
