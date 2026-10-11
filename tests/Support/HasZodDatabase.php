<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait HasZodDatabase
{
    use DatabaseTruncation;

    protected array $connectionsToTruncate = [
        'zod',
    ];

    /**
     * Optional PostGIS / MV setup. Each statement is savepoint-isolated so a
     * swallowed failure cannot abort the RefreshDatabase transaction (PG 25P02)
     * and break later Org::factory() inserts used by the Last Month card tests.
     */
    public function setupHasZodDatabase(): void
    {
        try {
            DB::connection('zod')->unprepared('SAVEPOINT setup_zod_postgis');
            DB::connection('zod')->unprepared('drop extension if exists address_standardizer_data_us CASCADE;');
            DB::connection('zod')->unprepared('alter table tiger.state add column if not exists simplified_geom geometry(Polygon,4326)');
            DB::connection('zod')->unprepared('RELEASE SAVEPOINT setup_zod_postgis');
        } catch (\Exception $exception) {
            try {
                DB::connection('zod')->unprepared('ROLLBACK TO SAVEPOINT setup_zod_postgis');
            } catch (\Exception) {
                //
            }
        }

        try {
            DB::connection('zod')->unprepared('SAVEPOINT setup_zod_spatial_ref');
            DB::connection('zod')->unprepared('INSERT INTO spatial_ref_sys (srid,auth_name,auth_srid,srtext,proj4text) VALUES (4326,\'EPSG\',4326,\'"GEOGCS[""WGS 84"",DATUM[""WGS_1984"",SPHEROID[""WGS 84"",6378137,298.257223563,AUTHORITY[""EPSG"",""7030""]],AUTHORITY[""EPSG"",""6326""]],PRIMEM[""Greenwich"",0,AUTHORITY[""EPSG"",""8901""]],UNIT[""degree"",0.0174532925199433,AUTHORITY[""EPSG"",""9122""]],AUTHORITY[""EPSG"",""4326""]]"\', \'+proj=longlat +datum=WGS84 +no_defs\')');
            DB::connection('zod')->unprepared('RELEASE SAVEPOINT setup_zod_spatial_ref');
        } catch (\Exception $exception) {
            try {
                DB::connection('zod')->unprepared('ROLLBACK TO SAVEPOINT setup_zod_spatial_ref');
            } catch (\Exception) {
                //
            }
        }

        foreach ([
            'aggregate_kit_scan_path_h',
            'aggregate_address_heatmap',
            'aggregate_address_geohash',
            'recovery_address',
            'recoveries',
        ] as $view) {
            try {
                DB::connection('zod')->unprepared('SAVEPOINT refresh_zod_view');
                Schema::connection('zod')->refreshMaterializedView($view, concurrently: false);
                DB::connection('zod')->unprepared('RELEASE SAVEPOINT refresh_zod_view');
            } catch (\Exception $exception) {
                try {
                    DB::connection('zod')->unprepared('ROLLBACK TO SAVEPOINT refresh_zod_view');
                } catch (\Exception) {
                    //
                }
            }
        }
    }
}
