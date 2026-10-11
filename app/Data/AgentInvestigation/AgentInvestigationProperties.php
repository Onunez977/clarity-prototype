<?php

namespace App\Data\AgentInvestigation;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class AgentInvestigationProperties extends Data
{
    public function __construct(
        #[DataCollectionOf(Search::class)]
        public array $searches = [],
        #[DataCollectionOf(SearchResult::class)]
        public array $searchResults = [],
    ) {}
}
