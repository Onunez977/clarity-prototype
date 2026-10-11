<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Company location kinds. Add a case here to offer another type on Configuration.
 */
enum OrgLocationType: string
{
    case MainOffice = 'main_office';
    case StorageFacility = 'storage_facility';
    case SatelliteOffice = 'satellite_office';

    public function label(): string
    {
        return match ($this) {
            self::MainOffice => 'Main Office',
            self::StorageFacility => 'Storage Facility',
            self::SatelliteOffice => 'Satellite Office',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MainOffice => '#FF6666',
            self::StorageFacility => '#2563eb',
            self::SatelliteOffice => '#059669',
        };
    }

    /**
     * Pin shape used on the map so types stay distinct without relying on color alone.
     */
    public function pin(): string
    {
        return match ($this) {
            self::MainOffice => 'pin',
            self::StorageFacility => 'square',
            self::SatelliteOffice => 'circle',
        };
    }

    public function allowsMultiple(): bool
    {
        return $this !== self::MainOffice;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
