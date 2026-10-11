<?php

namespace Database\Seeders;

use App\Console\Commands\SeedRolesAndPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Artisan::call(SeedRolesAndPermissions::class);
        $this->call(ClarityScoutPermissionSeeder::class);

        if (! app()->environment('testing')) {
            $this->call(InsightOrgSeeder::class);

            if (app()->environment('local')) {
                $this->call(LocalDataSeeder::class);
                $this->call(ClarityScoutClickhouseSeeder::class);
                $this->call(LocalActivitySeeder::class);
                $this->call(OttoSeeder::class);
            }
        }
    }
}
