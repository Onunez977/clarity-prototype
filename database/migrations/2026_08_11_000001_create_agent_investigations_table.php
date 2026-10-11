<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('conversation_id', 36);
            $table->jsonb('properties');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('agent_conversations')
                ->cascadeOnDelete();

            $table->unique(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_investigations');
    }
};
