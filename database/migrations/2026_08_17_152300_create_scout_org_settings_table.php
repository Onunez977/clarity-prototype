<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('platform')->hasTable('scout_org_settings')) {
            return;
        }

        Schema::connection('platform')->create('scout_org_settings', function (Blueprint $table) {
            $table->unsignedInteger('org_id')->primary();
            $table->jsonb('map_layers')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('scout_org_settings');
    }
};
