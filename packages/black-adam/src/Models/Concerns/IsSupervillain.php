<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Models\Concerns;

trait IsSupervillain
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->connection = 'zod';
    }
}
