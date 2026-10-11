<?php

namespace App\Data\AgentInvestigation;

use App\Datatype\SearchType;
use Spatie\LaravelData\Data;

class Search extends Data
{
    public function __construct(
        public string $searchId,
        public string $messageId,
        public array $parameters,
        public SearchType $searchType,
        public string $title = '',
        public string $notes = '',
        public string $source = 'otto',
        public ?int $createdBy = null,
        public string $conversationId = '',
        public bool $useful = false,
    ) {}
}
