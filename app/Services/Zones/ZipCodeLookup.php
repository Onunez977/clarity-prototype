<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * ZIP existence against the same Tiger ZCTA source {@see Zone::searchZipCodes()} uses.
 */
class ZipCodeLookup
{
    /**
     * @param  list<mixed>|string  $zipCodes
     * @return list<string>
     */
    public function normalize(array|string $zipCodes): array
    {
        $items = is_array($zipCodes) ? $zipCodes : [$zipCodes];
        $out = [];

        foreach ($items as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            if (preg_match_all('/\b(\d{5})\b/', (string) $item, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $zip) {
                $out[] = $zip;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<mixed>|string  $zipCodes
     * @return list<string>
     */
    public function existing(array|string $zipCodes): array
    {
        $normalized = $this->normalize($zipCodes);

        if ($normalized === []) {
            return [];
        }

        $found = DB::connection('zod')
            ->table('tiger_data.zcta5_all')
            ->whereIn('zcta5ce', $normalized)
            ->distinct()
            ->pluck('zcta5ce')
            ->map(static fn (mixed $zip): string => (string) $zip)
            ->all();

        return array_values(array_filter(
            $normalized,
            static fn (string $zip): bool => in_array($zip, $found, true),
        ));
    }
}
