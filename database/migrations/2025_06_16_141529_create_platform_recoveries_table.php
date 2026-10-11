<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recoveries')) {
            Schema::create('recoveries', function (Blueprint $table) {
                $table->id();
                $table->integer('integration_id')->nullable();
                $table->string('remote_order_id', 64)->nullable();
                $table->integer('status')->nullable();
                $table->integer('org_id')->nullable();
                $table->integer('client_org_id')->nullable();
                $table->integer('lienholder_org_id')->nullable();
                $table->integer('order_type')->nullable();
                $table->boolean('is_staged')->nullable();
                $table->string('plate', 12)->nullable();
                $table->string('us_state', 2)->nullable();
                $table->string('vin', 17)->nullable();
                $table->string('vehicle_year', 8)->nullable();
                $table->string('vehicle_make', 25)->nullable();
                $table->string('vehicle_model', 25)->nullable();
                $table->integer('lookback_days')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->bigInteger('address_id')->nullable();
                $table->string('house_number', 20)->nullable();
                $table->string('road', 128)->nullable();
                $table->string('city', 64)->nullable();
                $table->string('state', 32)->nullable();
                $table->string('postcode', 5)->nullable();
                // TODO: Geometry
                $table->string('geo')->nullable();
                $table->date('repo_date')->nullable();

                $table->unique('id', 'recoveries_id_uindex');
                $table->index('repo_date', 'recoveries_repo_date_idx');
                $table->index('org_id', 'recoveries_org_id_idx');
            });
        }
    }
};
