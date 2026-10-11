<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Scan;
use Geocodio\Geocodio;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class GeocoderController extends Controller
{
    public function geocode(Geocodio $geocodio, Request $request)
    {
        return $this->getCachedGeocode($request, function ($request) use ($geocodio) {
            $fields = $request->input('fields', []);
            $limit = $request->input('limit', 1);

            return $geocodio->geocode($request->input('data'), $fields, $limit);
        });
    }

    public function reverse(Geocodio $geocodio, Request $request)
    {
        return $this->getCachedGeocode($request, function ($request) use ($geocodio) {
            $fields = $request->input('fields', []);
            $limit = $request->input('limit', 1);

            $data = $geocodio->reverse($request->input('data'), $fields, $limit);

            if ($scan_id = $request->input('scan_id', null)) {
                $components = data_get($data, 'results.0.address_components', null);
                $address = data_get($data, 'results.0.formatted_address', null);

                if ($address) {
                    $simpleAddress = null;
                    if ($components) {
                        $simpleAddress = \trim($components['city'].', '.$components['state'].' '.$components['zip']);
                    }

                    Scan::find($scan_id)?->updateAddress($address, $simpleAddress);
                }
            }

            return $data;
        });
    }

    protected function getCachedGeocode(Request $request, \Closure $callback)
    {
        $key = 'geocode'.sha1(json_encode(Arr::wrap($request->only('data', 'scan_id', 'fields', 'limit'))));

        return cache()->remember(
            $key,
            now()->addDay(),
            fn () => $callback($request)
        );
    }
}
