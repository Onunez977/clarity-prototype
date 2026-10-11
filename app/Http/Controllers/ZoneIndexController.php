<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class ZoneIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Feature::active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'), Response::HTTP_FORBIDDEN);

        Gate::authorize('viewAny', Zone::class);

        $user = $request->user();

        $zones = Zone::query()
            ->forOrg($user->org_id)
            ->withGeoJson()
            ->with(['users:id,name'])
            ->orderBy('name')
            ->get();

        $features = $zones->map(function (Zone $zone): array {
            $geometry = $zone->geom_geojson ? json_decode($zone->geom_geojson, true) : null;
            $centroidGeo = $zone->centroid_geojson ? json_decode($zone->centroid_geojson, true) : null;
            $centroid = is_array($centroidGeo['coordinates'] ?? null) ? $centroidGeo['coordinates'] : null;

            return [
                'type' => 'Feature',
                'id' => $zone->id,
                'geometry' => $geometry,
                'properties' => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'description' => $zone->description,
                    'color' => $zone->color,
                    'is_no_scan_zone' => $zone->is_no_scan_zone,
                    'definition' => $zone->definition,
                    'centroid' => $centroid,
                    'user_ids' => $zone->users->pluck('id')->all(),
                    'user_names' => $zone->users->pluck('name')->all(),
                ],
            ];
        })->all();

        return response()->json(['zones' => $features]);
    }
}
