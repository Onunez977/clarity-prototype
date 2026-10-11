<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('platform')->hasTable('zones')) {
            return;
        }

        Schema::connection('platform')->create('zones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('org_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 16)->default('#2563eb');
            $table->jsonb('definition')->default(DB::raw("'{}'::jsonb"));
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // `orgs` lives in the `public` schema and other platform-schema tables
            // (invites, exports, imports) follow the convention of trusting org_id
            // at the app layer rather than crossing schemas with a FK. Scoping is
            // enforced via policies and explicit org_id filters on Zone queries.
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('org_id');
        });

        DB::connection('platform')->statement(
            'ALTER TABLE zones ADD COLUMN geom public.geometry(MultiPolygon, 4326)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX zones_geom_gist ON zones USING GIST (geom)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('zones');
    }
};
