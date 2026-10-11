<?php

namespace App\Data\AgentInvestigation;

use Spatie\LaravelData\Data;

class SearchResult extends Data
{
    public function __construct(
        public string $searchId,
        public int $resultId,
        public string $notes = '',
        public ?int $addedBy = null,
        public bool $useful = false,
    ) {}
}
