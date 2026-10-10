<?php

namespace Tests\Filament\Pages;

use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Filament\Widgets\Dashboard\UniqueScanCounts;

class ThrowingQueryCard extends UniqueScanCounts implements ExplainsCardQuery
{
    public function getCardQueryStatements(): array
    {
        throw new \RuntimeException('deliberate');
    }
}
