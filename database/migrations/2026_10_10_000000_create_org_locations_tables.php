<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('platform')->hasTable('org_locations')) {
            Schema::connection('platform')->create('org_locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('org_id');
                $table->string('name');
                $table->string('type');
                $table->text('address');
                $table->string('phone')->nullable();
                $table->text('notes')->nullable();
                $table->decimal('latitude', 9, 6)->nullable();
                $table->decimal('longitude', 9, 6)->nullable();
                $table->string('coverage_mode')->nullable();
                $table->decimal('coverage_radius_miles', 6, 1)->nullable();
                $table->jsonb('coverage_polygon')->nullable();
                $table->timestamps();

                $table->index('org_id');
                $table->index(['org_id', 'type']);
            });
        }

        if (! Schema::connection('platform')->hasTable('org_coverage_zones')) {
            Schema::connection('platform')->create('org_coverage_zones', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('org_id')->unique();
                $table->string('scope')->default('company');
                $table->string('mode')->default('radius');
                $table->decimal('radius_miles', 6, 1)->nullable();
                $table->foreignId('center_location_id')->nullable()->constrained('org_locations')->nullOnDelete();
                $table->jsonb('polygon')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('org_coverage_zones');
        Schema::connection('platform')->dropIfExists('org_locations');
    }
};
