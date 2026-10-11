<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class AverageRecoveryRateWidget extends ClientStatWidget
{
    protected function getLabel(): string
    {
        return 'Avg Recovery Rate';
    }

    protected function getDrillDownKey(): ?string
    {
        return 'avgRecoveryRate';
    }

    protected function getValueFromBatchCache(): mixed
    {
        return $this->calculateRecoveryRateFromBatch(
            $this->buildBatchCacheKeyForDates($this->getStartDate(), $this->getEndDate())
        );
    }

    protected function getPreviousValueFromBatchCache(): mixed
    {
        $previousDates = $this->getPreviousPeriodDates($this->date_type ?? 'this_month', $this->getTimezone());
        if ($previousDates === null) {
            return null;
        }

        return $this->calculateRecoveryRateFromBatch(
            $this->buildBatchCacheKeyForDates($previousDates['start'], $previousDates['end'])
        );
    }

    private function calculateRecoveryRateFromBatch(string $batchCacheKey): mixed
    {
        if ($this->client_org_id === null) {
            return null;
        }

        $batchData = cache()->get($batchCacheKey);

        if ($batchData === null || ! is_array($batchData)) {
            return null;
        }

        $clientData = $batchData[$this->client_org_id] ?? null;
        if ($clientData === null) {
            return null;
        }

        $assignments = (int) ($clientData['assignments'] ?? 0);
        $repos = (int) ($clientData['repos'] ?? 0);

        return $assignments > 0 ? (int) round(($repos / $assignments) * 100) : 0;
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        $baseQuery = fn () => Clickhouse\HotlistStatusSource::query($this->branch_id)
            ->forOrg($this->org_id)
            ->between($startDate, $endDate, $this->getTimezone())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id);

        $assignmentsCount = (int) $baseQuery()
            ->openAssignments()
            ->selectRaw(Clickhouse\HotlistStatusSource::totalExpression($this->branch_id))
            ->value('total') ?? 0;

        $repoCount = (int) $baseQuery()
            ->repossessions()
            ->selectRaw(Clickhouse\HotlistStatusSource::totalExpression($this->branch_id))
            ->value('total') ?? 0;

        return $assignmentsCount > 0
            ? (int) round(($repoCount / $assignmentsCount) * 100)
            : 0;
    }

    protected function formatValue(mixed $rawValue): string
    {
        return $rawValue.'%';
    }

    protected function getTooltipDescription(): ?string
    {
        return 'Percentage of assignments that resulted in recorded repossessions; Repossessions / Assignments';
    }
}
