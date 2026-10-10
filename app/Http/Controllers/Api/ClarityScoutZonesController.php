<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClarityScoutZonesController extends Controller
{
    /**
     * Return the zones a scanner user should see on their map: the zones
     * directly assigned to them, as GeoJSON features. Drivers only ever see
     * their own zones — neighboring/non-assigned zones are not surfaced.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $assignedIds = $user->zones()->pluck('zones.id')->all();

        $assigned = Zone::query()
            ->forOrg($user->org_id)
            ->whereIn('zones.id', $assignedIds)
            ->withGeoJson()
            ->get()
            ->map(fn (Zone $z) => $this->toFeature($z, includeDescription: true))
            ->all();

        return response()->json([
            'assigned' => $assigned,
        ]);
    }

    private function toFeature(Zone $zone, bool $includeDescription): array
    {
        $geometry = $zone->geom_geojson ? json_decode($zone->geom_geojson, true) : null;
        $centroidGeo = $zone->centroid_geojson ? json_decode($zone->centroid_geojson, true) : null;
        $centroid = is_array($centroidGeo['coordinates'] ?? null) ? $centroidGeo['coordinates'] : null;

        $properties = [
            'id' => $zone->id,
            'name' => $zone->name,
            'color' => $zone->color,
            'is_no_scan_zone' => $zone->is_no_scan_zone,
            'centroid' => $centroid,
        ];

        if ($includeDescription) {
            $properties['description'] = $zone->description;
        }

        return [
            'type' => 'Feature',
            'id' => $zone->id,
            'properties' => $properties,
            'geometry' => $geometry,
        ];
    }
}
