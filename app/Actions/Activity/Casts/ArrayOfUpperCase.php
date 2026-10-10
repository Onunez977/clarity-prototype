<?php

namespace App\Actions\Activity\Casts;

use Illuminate\Support\Arr;

/**
 * @codeCoverageIgnore
 */
final class ArrayOfUpperCase
{
    protected string $delimiter = ',';

    public static function make(): self
    {
        return new ArrayOfUpperCase;
    }

    public function set($value): array
    {
        if (is_string($value)) {
            $value = explode($this->delimiter, $value);
        }

        $data = Arr::wrap($value);

        return array_map('strtoupper', $data);
    }

    public function get($value): ?string
    {
        if (is_array($value)) {
            return implode($this->delimiter, $value);
        }

        return $value;
    }
}
