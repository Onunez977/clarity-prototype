<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('email');
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->foreignId('alert_setting_id')
                ->nullable()
                ->after('notifiable_id')
                ->constrained('alert_settings')
                ->nullOnDelete();
            $table->string('recipient')->nullable();
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->string('cc')->nullable();
            $table->string('bcc')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->text('headers')->nullable();
            $table->text('attachments')->nullable();
            $table->string('message_id')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->text('data')->nullable();
            $table->timestamp('opened')->nullable();
            $table->timestamp('delivered')->nullable();
            $table->timestamp('complaint')->nullable();
            $table->timestamp('bounced')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('alert_setting_id');
            $table->index('type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::drop('notification_logs');
    }
};
