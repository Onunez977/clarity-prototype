<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('platform');

        if (! $schema->hasTable('opportunity_drivers')) {
            $schema->create('opportunity_drivers', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('org_id');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('kit_name');
                $table->decimal('latitude', 9, 6);
                $table->decimal('longitude', 9, 6);
                $table->unsignedInteger('fix_minutes_ago')->default(0);
                $table->timestamps();

                $table->index('org_id');
            });
        }

        if (! $schema->hasTable('opportunity_cases')) {
            $schema->create('opportunity_cases', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('org_id');
                $table->string('plate');
                $table->string('us_state', 2)->default('IN');
                $table->string('vin');
                $table->unsignedSmallInteger('vehicle_year')->nullable();
                $table->string('vehicle_make')->nullable();
                $table->string('vehicle_model')->nullable();
                $table->string('client_name');
                $table->string('branch_name');
                $table->string('remote_order_id');
                $table->date('order_date');
                $table->timestamps();

                $table->index('org_id');
                $table->unique(['org_id', 'plate']);
            });
        }

        if (! $schema->hasTable('opportunity_addresses')) {
            $schema->create('opportunity_addresses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->constrained('opportunity_cases')->cascadeOnDelete();
                $table->unsignedSmallInteger('position');
                $table->string('line');
                $table->string('city');
                $table->string('state', 2)->default('IN');
                $table->decimal('latitude', 9, 6)->nullable();
                $table->decimal('longitude', 9, 6)->nullable();
                $table->timestamps();

                $table->index('case_id');
            });
        }

        if (! $schema->hasTable('opportunity_hits')) {
            $schema->create('opportunity_hits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->constrained('opportunity_cases')->cascadeOnDelete();
                $table->foreignId('address_id')->nullable()->constrained('opportunity_addresses')->nullOnDelete();
                $table->dateTime('scanned_at');
                $table->string('hit_type', 1);
                $table->decimal('distance_miles', 8, 3)->nullable();
                $table->unsignedTinyInteger('local_hour')->default(12);
                $table->string('disposition')->nullable();
                $table->boolean('state_mismatch')->default(false);
                $table->string('kit_name')->nullable();
                $table->timestamps();

                $table->index(['case_id', 'scanned_at']);
            });
        }

        if (! $schema->hasTable('opportunity_check_ins')) {
            $schema->create('opportunity_check_ins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->constrained('opportunity_cases')->cascadeOnDelete();
                $table->foreignId('address_id')->constrained('opportunity_addresses')->cascadeOnDelete();
                $table->dateTime('matched_at');
                $table->string('kit_name')->nullable();
                $table->timestamps();

                $table->index('case_id');
            });
        }

        if (! $schema->hasTable('opportunity_dispatches')) {
            $schema->create('opportunity_dispatches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('case_id')->unique()->constrained('opportunity_cases')->cascadeOnDelete();
                $table->foreignId('driver_id')->constrained('opportunity_drivers')->cascadeOnDelete();
                $table->string('priority')->default('normal');
                $table->string('due_window')->default('overnight');
                $table->text('note')->nullable();
                $table->string('status')->default('dispatched');
                $table->unsignedInteger('stop_order')->default(0);
                $table->text('notification_log')->nullable();
                $table->timestamps();

                $table->index(['driver_id', 'stop_order']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('platform');
        $schema->dropIfExists('opportunity_dispatches');
        $schema->dropIfExists('opportunity_check_ins');
        $schema->dropIfExists('opportunity_hits');
        $schema->dropIfExists('opportunity_addresses');
        $schema->dropIfExists('opportunity_cases');
        $schema->dropIfExists('opportunity_drivers');
    }
};
