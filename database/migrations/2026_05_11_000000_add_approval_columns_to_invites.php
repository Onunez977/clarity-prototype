<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->timestamp('approved_at')
                ->nullable()
                ->after('accepted_at');

            $table->timestamp('notification_sent_at')
                ->nullable()
                ->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'notification_sent_at']);
        });
    }
};
