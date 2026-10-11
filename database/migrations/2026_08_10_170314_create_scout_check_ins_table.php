<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('platform')->create('scout_check_ins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kit_id');
            $table->unsignedInteger('org_id');
            $table->unsignedBigInteger('hotlist_id');
            $table->unsignedBigInteger('address_id');
            $table->boolean('is_staged');
            $table->timestampTz('matched_at');
            $table->timestampTz('hour_bucket');
            $table->double('lat');
            $table->double('lng');
            $table->timestamps();

            $table->unique(['kit_id', 'address_id', 'hour_bucket']);
            $table->index(['org_id', 'address_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('scout_check_ins');
    }
};
