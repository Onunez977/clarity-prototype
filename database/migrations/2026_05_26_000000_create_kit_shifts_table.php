<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('platform')->hasTable('kit_shifts')) {
            return;
        }

        Schema::connection('platform')->create('kit_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kit_id');
            $table->unsignedInteger('org_id');
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_type', 16)->nullable();
            $table->timestampTz('summary_sent_at')->nullable();
            $table->string('summary_skipped_reason', 64)->nullable();
            $table->timestamps();

            // Scoping is enforced at the app layer (cross-schema convention used
            // throughout platform-schema tables); index for query performance.
            $table->index(['org_id', 'started_at']);
            $table->index(['kit_id', 'started_at']);
        });

        // Partial unique index — at most one open shift per kit at any time.
        // Done via raw SQL because Blueprint doesn't support partial indexes natively.
        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX kit_shifts_one_open_per_kit
             ON kit_shifts (kit_id)
             WHERE ended_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('kit_shifts');
    }
};
