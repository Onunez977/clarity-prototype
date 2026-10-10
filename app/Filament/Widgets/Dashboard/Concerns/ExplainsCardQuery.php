<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use App\Dto\CardQueryStatement;

interface ExplainsCardQuery
{
    /**
     * The statement(s) resolving this card's HEADLINE value for $this->date on a COLD CACHE --
     * not necessarily what ran this request. Values are cached (closed months for a year), so
     * an expand almost never re-executes. CardQuerySqlFidelityTest is what holds the two equal.
     *
     * @return array<int, CardQueryStatement>
     */
    public function getCardQueryStatements(): array;
}
