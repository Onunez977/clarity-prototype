<?php

namespace App\Support\Otto;

use App\Filament\Tables\Filters\PlateFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Normalizes plates for Otto search tools.
 *
 * `*` is a user-facing wildcard (prefix / suffix / contains). Other punctuation
 * is stripped. Plates without `*` keep the Hits/Scans contains (`LIKE %plate%`)
 * behavior from {@see PlateFilter}.
 */
final class PlatePattern
{
    public static function normalize(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9*]/i', '', trim($plate)));
    }

    public static function apply(Builder $query, string $column, string $plate): Builder
    {
        $normalized = self::normalize($plate);

        if ($normalized === '') {
            return $query;
        }

        if (str_contains($normalized, '*')) {
            return $query->where($column, 'LIKE', str_replace('*', '%', $normalized));
        }

        return PlateFilter::modifyQuery($query, $column, $normalized);
    }
}
