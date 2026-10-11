<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->integer('org_id')->index();
            $table->integer('branch_id')->nullable();
            $table->string('udbsaid')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('phone')->nullable();
            $table->string('fax')->nullable();
            $table->timestamps();

            $table->index('branch_id', 'branches_branch_id_idx');
            $table->index(['org_id', 'branch_id'], 'branches_org_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
