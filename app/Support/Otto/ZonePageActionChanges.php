<?php

declare(strict_types=1);

namespace App\Support\Otto;

use App\Ai\PageActionChange;

/**
 * Shared confirmation-card rows for Otto zone create / update proposals.
 */
class ZonePageActionChanges
{
    /**
     * @param  array{
     *     name: string,
     *     description: ?string,
     *     color: string,
     *     is_no_scan_zone: bool,
     *     zip_codes: list<string>,
     *     user_ids: list<int>,
     *     assigned_driver_names: list<string>
     * }  $resolved
     * @return list<PageActionChange>
     */
    public static function forResolved(array $resolved, ?int $zoneId = null): array
    {
        $changes = [];

        if ($zoneId !== null) {
            $changes[] = new PageActionChange(
                'zone_id',
                'Zone',
                $resolved['name'].' (#'.$zoneId.')',
                $zoneId,
            );
        }

        $changes[] = new PageActionChange('name', 'Name', PageActionCopy::zoneName($resolved['name']), $resolved['name']);

        if ($resolved['description'] !== null) {
            $changes[] = new PageActionChange(
                'description',
                'Description',
                PageActionCopy::zoneDescription($resolved['description']),
                $resolved['description'],
            );
        }

        $changes[] = new PageActionChange(
            'color',
            'Color',
            PageActionCopy::zoneColor($resolved['color']),
            $resolved['color'],
        );

        $changes[] = new PageActionChange(
            'is_no_scan_zone',
            'No-scan',
            PageActionCopy::zoneNoScan($resolved['is_no_scan_zone']),
            $resolved['is_no_scan_zone'],
        );

        if ($resolved['zip_codes'] !== []) {
            $changes[] = new PageActionChange(
                'zip_codes',
                'ZIP codes',
                PageActionCopy::zoneZipCodes($resolved['zip_codes']),
                $resolved['zip_codes'],
            );
        }

        if ($resolved['user_ids'] !== []) {
            $changes[] = new PageActionChange(
                'assigned_user_ids',
                'Assigned drivers',
                PageActionCopy::zoneDrivers($resolved['assigned_driver_names']),
                $resolved['user_ids'],
            );
        }

        return $changes;
    }
}
