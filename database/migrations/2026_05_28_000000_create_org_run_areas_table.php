<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('platform')->hasTable('org_run_areas')) {
            return;
        }

        Schema::connection('platform')->create('org_run_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('org_id')->unique();
            $table->jsonb('inputs')->default(DB::raw("'[]'::jsonb"));
            $table->string('source_vintage', 255)->nullable();
            $table->timestampTz('materialized_at')->nullable();
            $table->timestampsTz();
        });

        // PostGIS lives in `public`; platform's search_path is `platform,public`.
        // SRID 4326 to match `zones.geom`.
        DB::connection('platform')->statement(
            'ALTER TABLE org_run_areas ADD COLUMN geometry public.geometry(MultiPolygon, 4326)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX org_run_areas_geometry_gist ON org_run_areas USING GIST (geometry)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('org_run_areas');
    }
};
