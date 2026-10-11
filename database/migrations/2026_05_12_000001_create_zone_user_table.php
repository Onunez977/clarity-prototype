<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('platform')->hasTable('zone_user')) {
            return;
        }

        Schema::connection('platform')->create('zone_user', function (Blueprint $table) {
            $table->unsignedBigInteger('zone_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->primary(['zone_id', 'user_id']);
            $table->foreign('zone_id')->references('id')->on('zones')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('zone_user');
    }
};
