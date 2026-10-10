<?php

declare(strict_types=1);

namespace LaravelClickhouseEloquent\Expressions;

interface InsertExpression
{
    public function needsEncoding(): bool;

    public function getValue(): string;
}
