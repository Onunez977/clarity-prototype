<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('kit_id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->unique();
            $table->string('vehicle_type', 32)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kit_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('kit_id')->nullable()->after('org_id');
        });

        Schema::dropIfExists('kit_settings');
    }
};
