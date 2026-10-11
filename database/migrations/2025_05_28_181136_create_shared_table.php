<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared', function (Blueprint $table) {
            $table->id();
            $table->morphs('shared');
            $table->morphs('shared_with');
            $table->timestamp('expires_at')
                ->nullable();
            $table->integer('status_id')
                ->default(0);
            $table->timestamps();
            $table->timestamp('deleted_at')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared');
    }
};
