<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'scout_onboarding_nonce')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('scout_onboarding_nonce');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'scout_onboarding_nonce')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('scout_onboarding_nonce')->default(0);
        });
    }
};
