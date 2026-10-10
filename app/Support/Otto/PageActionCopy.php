<?php

namespace App\Support\Otto;

use App\Ai\PageActionChange;
use App\Models\Zone;

/**
 * User-visible copy for Otto page-action chips. Filament toggle keys
 * (`last_7d`) and Otto preset slugs (`last_7_days`) stay on the apply
 * payload — they must never appear in {@see PageActionChange::$summary}.
 */
class PageActionCopy
{
    /**
     * Human phrase for a known Otto/Filament time-range token, or null when
     * the caller should format a custom date window instead.
     */
    public static function timeRangePhrase(?string $range): ?string
    {
        if ($range === null || trim($range) === '') {
            return null;
        }

        return match (strtolower(trim($range))) {
            'today' => 'today',
            'yesterday' => 'yesterday',
            'last_7_days', '7d', 'last_7d' => 'the last 7 days',
            'last_30_days', '30d', 'last_30d' => 'the last 30 days',
            'last_90_days', '90d', 'last_90d' => 'the last 90 days',
            'last_12_hours', '12h' => 'the last 12 hours',
            default => null,
        };
    }

    public static function timeRangeSummary(string $phrase): string
    {
        return "Set the time range to {$phrase}";
    }

    public static function zoneName(string $name): string
    {
        return "Name this zone {$name}";
    }

    public static function zoneDescription(string $description): string
    {
        return 'Set the description';
    }

    public static function zoneColor(string $color): string
    {
        $label = Zone::PRESET_COLORS[$color] ?? ($color === Zone::NO_SCAN_COLOR ? 'no-scan gray' : $color);

        return "Set the map color to {$label}";
    }

    public static function zoneNoScan(bool $on): string
    {
        return $on ? 'Mark as a no-scan zone' : 'Do not mark as a no-scan zone';
    }

    /**
     * @param  list<string>  $zips
     */
    public static function zoneZipCodes(array $zips): string
    {
        return 'Use ZIP codes '.implode(', ', $zips);
    }

    /**
     * @param  list<string>  $names
     */
    public static function zoneDrivers(array $names): string
    {
        return 'Assign to '.implode(', ', $names);
    }
}
