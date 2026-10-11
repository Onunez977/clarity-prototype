<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use stdClass;

class NearestGivenAddressController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (Feature::for($request->user())->inactive(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) && session()->missing('admin_impersonator')) {
            return response()->json([]);
        }

        $request->validate([
            'hotlist_id' => 'required|integer',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $hotlistId = (int) $request->input('hotlist_id');
        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');

        if ($lat == 0 || $lng == 0) {
            return response()->json([]);
        }

        $nearest = $this->nearestGeocodedHotlistAddress($hotlistId, $lat, $lng);

        if ($nearest === null) {
            return response()->json([]);
        }

        return response()->json([
            'address' => $nearest->formatted_address,
            'distance_miles' => (float) $nearest->distance_miles,
        ]);
    }

    /**
     * Nearest geocoded hotlist address on the zod connection.
     * Same Given source as ListHits::buildPostgresQuery: hotlist_address ⋈ address, geo IS NOT NULL, no type_id filter.
     */
    private function nearestGeocodedHotlistAddress(int $hotlistId, float $lat, float $lng): ?stdClass
    {
        $origin = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)';

        return Address::query()
            ->from('address as a')
            ->join('hotlist_address as ha', 'a.id', '=', 'ha.address_id')
            ->where('ha.hotlist_id', $hotlistId)
            ->whereNotNull('a.geo')
            ->selectRaw(
                "CONCAT_WS(', ',
                    NULLIF(CONCAT_WS(' ', a.house_number, a.road), ''),
                    a.city,
                    CONCAT_WS(' ', a.state, a.postcode)
                ) as formatted_address,
                ROUND((ST_DistanceSphere({$origin}, a.geo::geometry) * 0.000621371)::numeric, 2) as distance_miles",
                [$lng, $lat],
            )
            ->orderByRaw("ST_DistanceSphere({$origin}, a.geo::geometry) asc", [$lng, $lat])
            ->limit(1)
            ->toBase()
            ->first();
    }
}
