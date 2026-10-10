<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\SharedHitStatsData;

class StagingHistoricalHits extends DashboardWidget implements ExplainsCardQuery
{
    use SharedHitStatsData;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Staging Historical Hits';

    protected function getTooltipDescription(): ?string
    {
        return 'Past scans matched to a staged assignment after the fact, once per plate. The total below counts every hit.';
    }

    public function getDatasetLabel(): string
    {
        return 'Staging Historical Hits';
    }

    public function getRgbColor(): array
    {
        return [255, 185, 0];
    }

    public function getHitStatsColumn(): string
    {
        return 'staging_historical_hit_count';
    }

    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return 0;
    }
}
