<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;

class ThirtyDayRecoveryRateWidget extends ClientStatWidget
{
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStartDate(): string
    {
        return now(tz: $this->getTimezone())->utc()->subDays(30)->startOfDay()->toDateTimeString();
    }

    protected function getEndDate(): string
    {
        return now(tz: $this->getTimezone())->utc()->toDateTimeString();
    }

    protected function getCacheTTL(int $defaultMinutes = 30): \DateTimeInterface|\DateInterval|int
    {
        return parent::getCacheTTL($defaultMinutes);
    }

    protected function getLabel(): string
    {
        return '30-Day Recovery Rate';
    }

    protected function getModelQuery(): int
    {
        $baseQuery = fn () => Clickhouse\HotlistStatusSource::query($this->branch_id)
            ->forOrg($this->org_id)
            ->between($this->getStartDate(), $this->getEndDate())
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
        return 'Recovery rate over the last 30 days (Repossessions / Assignments).';
    }
}
