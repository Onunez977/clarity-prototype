<?php

namespace App\Support;

use Illuminate\Support\Str;

class UserColor
{
    /** Curated palette: contrasts with the indigo app theme, legible in light & dark. */
    private const PALETTE = [
        '#0D9488', // teal-600
        '#0891B2', // cyan-600
        '#059669', // emerald-600
        '#65A30D', // lime-600
        '#CA8A04', // yellow-600
        '#D97706', // amber-600
        '#EA580C', // orange-600
        '#DC2626', // red-600
        '#DB2777', // pink-600
        '#C026D3', // fuchsia-600
        '#9333EA', // purple-600
        '#7C3AED', // violet-600
    ];

    public static function for(int|string $seed): string
    {
        $palette = self::PALETTE;

        return $palette[abs(crc32((string) $seed)) % count($palette)];
    }

    public static function initials(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name);
        $first = Str::substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? Str::substr($parts[count($parts) - 1], 0, 1) : '';

        return Str::upper($first.$last);
    }
}
