<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Models\Kit;
use App\Models\Zone;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ZoneMapDisplay extends Field
{
    protected string $view = 'forms.components.zone-map-display';

    /** @var Collection<int, Zone>|null */
    private ?Collection $cachedZones = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrated(false);
    }

    /**
     * Zones assigned to the kit's user, fetched once per request.
     *
     * @return Collection<int, Zone>
     */
    private function getZones(): Collection
    {
        if ($this->cachedZones !== null) {
            return $this->cachedZones;
        }

        $record = $this->getRecord();
        $user = $record instanceof Kit ? $record->settings?->user : null;

        if (! $user) {
            return $this->cachedZones = new Collection;
        }

        return $this->cachedZones = $user->zones()
            ->withGeoJson()
            ->orderBy('name')
            ->get([
                'zones.id',
                'zones.name',
                'zones.description',
                'zones.color',
                'zones.is_no_scan_zone',
                'zones.definition',
            ]);
    }

    /**
     * Lightweight list of assigned zones for chip rendering.
     *
     * @return array<int, array{id: int, name: string, color: string, is_no_scan_zone: bool}>
     */
    public function getZoneChips(): array
    {
        return $this->getZones()
            ->map(fn (Zone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'color' => $zone->color ?: '#2563eb',
                'is_no_scan_zone' => (bool) $zone->is_no_scan_zone,
            ])
            ->all();
    }

    /**
     * GeoJSON FeatureCollection of every polygon and ZIP footprint across all
     * zones assigned to the kit's user, color-coded per zone.
     */
    public function getZonesGeoJson(): array
    {
        $zones = $this->getZones();

        if ($zones->isEmpty()) {
            return [
                'features' => ['type' => 'FeatureCollection', 'features' => []],
                'no_scan_badges' => [],
            ];
        }

        $features = [];
        $zipToZone = [];
        $noScanBadges = [];

        foreach ($zones as $zone) {
            $color = $zone->color ?: '#2563eb';
            $definition = $zone->definition ?? [];

            $baseProps = [
                'zone_id' => $zone->id,
                'zone_name' => $zone->name,
                'zone_description' => $zone->description,
                'color' => $color,
                'is_no_scan_zone' => (bool) $zone->is_no_scan_zone,
            ];

            foreach ($definition['polygons'] ?? [] as $idx => $geojson) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => $baseProps + ['kind' => 'polygon', 'index' => $idx],
                    'geometry' => is_string($geojson) ? json_decode($geojson, true) : $geojson,
                ];
            }

            foreach ($definition['zip_codes'] ?? [] as $zip) {
                $zipToZone[(string) $zip] = $zone;
            }

            if ($zone->is_no_scan_zone) {
                $centroidGeo = $zone->centroid_geojson ? json_decode($zone->centroid_geojson, true) : null;
                $coords = is_array($centroidGeo['coordinates'] ?? null) ? $centroidGeo['coordinates'] : null;

                if ($coords) {
                    $noScanBadges[] = [
                        'zone_id' => $zone->id,
                        'zone_name' => $zone->name,
                        'centroid' => $coords,
                    ];
                }
            }
        }

        if ($zipToZone !== []) {
            $zipCodes = array_keys($zipToZone);
            $placeholders = implode(',', array_fill(0, count($zipCodes), '?'));

            $rows = DB::connection('zod')
                ->table('tiger_data.zcta5_all')
                ->whereRaw("zcta5ce IN ($placeholders)", $zipCodes)
                ->groupBy('zcta5ce')
                ->get([
                    'zcta5ce',
                    DB::raw('ST_AsGeoJSON(ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(ST_Union(the_geom), 4326)), 3))) AS shape'),
                ]);

            foreach ($rows as $row) {
                $zone = $zipToZone[$row->zcta5ce] ?? null;

                if (! $zone instanceof Zone) {
                    continue;
                }

                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'zone_id' => $zone->id,
                        'zone_name' => $zone->name,
                        'zone_description' => $zone->description,
                        'color' => $zone->color ?: '#2563eb',
                        'is_no_scan_zone' => (bool) $zone->is_no_scan_zone,
                        'kind' => 'zip',
                        'zip' => $row->zcta5ce,
                    ],
                    'geometry' => json_decode($row->shape, true),
                ];
            }
        }

        return [
            'features' => [
                'type' => 'FeatureCollection',
                'features' => $features,
            ],
            'no_scan_badges' => $noScanBadges,
        ];
    }
}
