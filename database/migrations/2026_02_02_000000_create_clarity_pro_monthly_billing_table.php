<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarity_pro_monthly_billing', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('org_id')->index();
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->smallInteger('days_active');
            $table->json('features_used');
            $table->boolean('still_active')->default(false);
            $table->timestamp('first_enabled_at')->nullable();
            $table->timestamp('last_state_change_at')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarity_pro_monthly_billing');
    }
};
