<?php

namespace App\Services\Geospatial\Concerns;

use App\Models\AggregateKitScanPathH;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Datatype\EndDate;
use InsightLPR\BlackAdam\Datatype\StartDate;

/**
 * @codeCoverageIgnore
 */
trait GeneratesPathData
{
    public function getPathLayerData()
    {
        return $this->baseQuery()
            ->selectRaw("
json_agg(
    jsonb_build_object(
        'kit_id',
        kit_id,
         'color', CASE
   when kit_id % 42 = 0 then json_build_array(128,0,0)
   when kit_id % 42 = 1 then json_build_array(165,42,42)
   when kit_id % 42 = 2 then json_build_array(220,20,60)
   when kit_id % 42 = 3 then json_build_array(255,99,71)
   when kit_id % 42 = 4 then json_build_array(205,92,92)
   when kit_id % 42 = 5 then json_build_array(233,150,122)
   when kit_id % 42 = 6 then json_build_array(255,69,0)
   when kit_id % 42 = 7 then json_build_array(255,140,0)
   when kit_id % 42 = 8 then json_build_array(255,165,0)
   when kit_id % 42 = 9 then json_build_array(255,215,0)
   when kit_id % 42 = 10 then json_build_array(189,183,107)
   when kit_id % 42 = 11 then json_build_array(128,128,0)
   when kit_id % 42 = 12 then json_build_array(154,205,50)
   when kit_id % 42 = 13 then json_build_array(107,142,35)
   when kit_id % 42 = 14 then json_build_array(0,100,0)
   when kit_id % 42 = 15 then json_build_array(34,139,34)
   when kit_id % 42 = 16 then json_build_array(0,255,0)
   when kit_id % 42 = 17 then json_build_array(46,139,87)
   when kit_id % 42 = 18 then json_build_array(60,179,113)
   when kit_id % 42 = 19 then json_build_array(47,79,79)
   when kit_id % 42 = 20 then json_build_array(0,128,128)
   when kit_id % 42 = 21 then json_build_array(0,139,139)
   when kit_id % 42 = 22 then json_build_array(0,255,255)
   when kit_id % 42 = 23 then json_build_array(0,191,255)
   when kit_id % 42 = 24 then json_build_array(25,25,112)
   when kit_id % 42 = 25 then json_build_array(0,0,205)
   when kit_id % 42 = 26 then json_build_array(0,0,255)
   when kit_id % 42 = 27 then json_build_array(65,105,225)
   when kit_id % 42 = 28 then json_build_array(138,43,226)
   when kit_id % 42 = 29 then json_build_array(75,0,130)
   when kit_id % 42 = 30 then json_build_array(72,61,139)
   when kit_id % 42 = 31 then json_build_array(106,90,205)
   when kit_id % 42 = 32 then json_build_array(147,112,219)
   when kit_id % 42 = 33 then json_build_array(153,50,204)
   when kit_id % 42 = 34 then json_build_array(128,0,128)
   when kit_id % 42 = 35 then json_build_array(255,0,255)
   when kit_id % 42 = 36 then json_build_array(218,112,214)
   when kit_id % 42 = 37 then json_build_array(199,21,133)
   when kit_id % 42 = 38 then json_build_array(255,20,147)
   when kit_id % 42 = 39 then json_build_array(139,69,19)
   when kit_id % 42 = 40 then json_build_array(210,105,30)
   when kit_id % 42 = 41 then json_build_array(244,164,96)

           end,
        'name',
        kits.name,
        'layerId',
        '".$this->layer."',
        'path',
        geo::json->'coordinates'
      )) as geojson")
            ->join('kits', 'kits.id', '=', 'aggregate_kit_scan_path_h.kit_id')
            ->pluck('geojson')
            ->first();
    }

    public function getGeoJsonData()
    {
        return $this->baseQuery()
            ->selectRaw('json_build_object(
    \'type\', \'FeatureCollection\',
    \'features\', json_agg(ST_AsGeoJSON(aggregate_kit_scan_path_h.*)::json)
        ) as geojson')
            ->pluck('geojson')
            ->first();
    }

    protected function baseQuery(): Builder
    {
        $query = AggregateKitScanPathH::query()
            ->whereIntegerInRaw('kit_id', $this->getKits())
            ->whereBetween('timestamp', [
                StartDate::parse($this->start->toDateTime()),
                EndDate::parse($this->end->toDateTime()),
            ]);

        /**
         * If the location is set filter out paths that are not within the state
         */
        if ($this->location) {
            $expression = DB::connection('platform')
                ->query()
                ->from('tiger_data.state_all')
                ->selectRaw('st_setsrid(the_geom,4326) as geom')
                ->where('stusps', $this->location);

            $query->withExpression('state_geom', $expression);
            $query->whereRaw('st_within(geo::geometry, (select geom from state_geom))');
        }

        return $query;
    }
}
