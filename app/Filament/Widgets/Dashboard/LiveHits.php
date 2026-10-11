<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Widgets\Dashboard\Concerns\DashboardWidget;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\Concerns\SharedHitStatsData;

class LiveHits extends DashboardWidget implements ExplainsCardQuery
{
    use SharedHitStatsData;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Direct Live Hits';

    protected function getTooltipDescription(): ?string
    {
        return 'Scans matching your open direct hotlist at scan time, once per plate. The total below counts every hit.';
    }

    public function getDatasetLabel(): string
    {
        return 'Direct Live Hits';
    }

    public function getRgbColor(): array
    {
        return [255, 102, 102];
    }

    public function getHitStatsColumn(): string
    {
        return 'live_hit_count';
    }

    protected function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return 0;
    }
}
