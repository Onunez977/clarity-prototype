<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aggregate_rdn_hotlist_count_by_hour')) {
            Schema::create('aggregate_rdn_hotlist_count_by_hour', function (Blueprint $table) {
                $table->integer('org_id');
                $table->integer('client_org_id')->nullable();
                $table->timestamp('hour_timestamp');
                $table->string('branch_id', 255)->nullable();
                $table->integer('order_type')->nullable();
                $table->integer('hotlist_count')->default(0);

                $table->index('org_id', 'idx_aggregate_rdn_hotlist_org_id');
                $table->index(['org_id', 'client_org_id'], 'idx_aggregate_rdn_hotlist_client_org_id');
                $table->index(['org_id', 'hour_timestamp'], 'idx_aggregate_rdn_hotlist_hour_timestamp');
                $table->index(['org_id', 'order_type'], 'idx_aggregate_rdn_hotlist_order_type');

                $table->index(
                    ['org_id', 'client_org_id', 'hour_timestamp', 'branch_id', 'order_type'],
                    'idx_aggregate_rdn_hotlist_composite'
                );

                $table->unique(
                    ['org_id', 'client_org_id', 'hour_timestamp', 'branch_id', 'order_type'],
                    'idx_aggregate_rdn_hotlist_unique'
                );
            });

            DB::statement(
                'CREATE INDEX idx_aggregate_rdn_hotlist_branch_id
                 ON aggregate_rdn_hotlist_count_by_hour(org_id, branch_id)
                 WHERE branch_id IS NOT NULL'
            );
        }
    }
};
