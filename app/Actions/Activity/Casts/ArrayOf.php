<?php

namespace App\Actions\Activity\Casts;

use Illuminate\Support\Arr;

/**
 * @codeCoverageIgnore
 */
final class ArrayOf
{
    protected string $delimiter = ',';

    public static function make(): self
    {
        return new ArrayOf;
    }

    public function set($value): array
    {
        if (is_string($value)) {
            $value = explode($this->delimiter, $value);
        }

        return Arr::wrap($value);
    }

    public function get($value): ?string
    {
        if (is_array($value)) {
            return implode($this->delimiter, $value);
        }

        return $value;
    }
}
