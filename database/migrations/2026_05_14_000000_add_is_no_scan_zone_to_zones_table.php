<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->table('zones', function (Blueprint $table) {
            $table->boolean('is_no_scan_zone')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('zones', function (Blueprint $table) {
            $table->dropColumn('is_no_scan_zone');
        });
    }
};
