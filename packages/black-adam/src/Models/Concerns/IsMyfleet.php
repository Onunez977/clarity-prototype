<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Models\Concerns;

trait IsMyfleet
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->connection = 'myfleet';
    }
}
