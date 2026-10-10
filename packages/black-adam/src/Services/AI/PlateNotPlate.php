<?php

namespace InsightLPR\BlackAdam\Services\AI;

use Illuminate\Support\Facades\Http;

class PlateNotPlate
{

    public static function make(string $guid = null){
        \Illuminate\Support\Facades\Validator::make([
            'guid' => $guid
        ], [
            'guid' => 'required|string'
        ]);
        $response = Http::get('https://ml.insightlpr.io/guid/plate-not-plate/'.$guid);
        return $response->json();
    }

}
