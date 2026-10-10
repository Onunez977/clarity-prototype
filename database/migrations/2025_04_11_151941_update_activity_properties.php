<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('alter table '.config('activitylog.table_name').' alter column properties type jsonb using properties::jsonb');
        } catch (Exception $exception) {
            // Already exists
        }

        try {
            DB::statement('create index activity_log_properties_index on '.config('activitylog.table_name').' USING GIN (properties jsonb_path_ops)');
        } catch (Exception $exception) {
            // Already exists
        }
    }
};
