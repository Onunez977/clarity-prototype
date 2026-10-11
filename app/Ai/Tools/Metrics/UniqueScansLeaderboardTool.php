<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Filament\Widgets\Dashboard\UniqueScansLeaderboard;

class UniqueScansLeaderboardTool extends CompanyMetricTool
{
    protected function card(): string
    {
        return UniqueScansLeaderboard::class;
    }
}
