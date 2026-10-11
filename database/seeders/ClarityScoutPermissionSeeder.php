<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class ClarityScoutPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('clarity-scout', 'web');
        Permission::findOrCreate('use-otto', 'web');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
