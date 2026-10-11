<?php

namespace App\Support\Geocoding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The zod schema dump ships tiger.state without the geometry columns the
 * public.scans insert trigger reads, so any Scan insert fails until they exist.
 */
class TigerStateColumns
{
    /**
     * @return list<string> the columns that were added
     */
    public static function ensure(): array
    {
        $added = [];

        if (! Schema::connection('zod')->hasColumn('tiger.state', 'the_geom')) {
            DB::connection('zod')->statement("ALTER TABLE tiger.state ADD COLUMN the_geom geometry constraint enforce_dims_the_geom
            check (st_ndims(the_geom) = 2)
        constraint enforce_geotype_the_geom
            check ((geometrytype(the_geom) = 'MULTIPOLYGON'::text) OR (the_geom IS NULL))
        constraint enforce_srid_the_geom
            check (st_srid(the_geom) = 4269)");
            $added[] = 'the_geom';
        }

        if (! Schema::connection('zod')->hasColumn('tiger.state', 'simplified_geom')) {
            DB::connection('zod')->statement('ALTER TABLE tiger.state ADD COLUMN simplified_geom geometry');
            $added[] = 'simplified_geom';
        }

        if (! Schema::connection('zod')->hasColumn('tiger.state', 'geom_4326')) {
            DB::connection('zod')->statement('ALTER TABLE tiger.state ADD COLUMN geom_4326 geometry generated always as (st_transform(the_geom, 4326)) stored');
            $added[] = 'geom_4326';
        }

        return $added;
    }
}
