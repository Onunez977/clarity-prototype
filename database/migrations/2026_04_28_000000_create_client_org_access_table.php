<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_org_access')) {
            return;
        }

        Schema::create('client_org_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('client_org_id');
            $table->unsignedInteger('repo_org_id');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['client_org_id', 'repo_org_id']);
            $table->index('repo_org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_org_access');
    }
};
