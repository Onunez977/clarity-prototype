<?php

namespace App\Dto;

final readonly class CardQueryStatement
{
    public function __construct(
        public string $label,
        public string $sql,
        public ?string $note = null,
        public ?string $queryClass = null,
    ) {}
}
