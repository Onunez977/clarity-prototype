<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SeedRolesAndPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:seed-permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed Roles and Permissions';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $admin_role = Role::findOrCreate(RoleEnum::ADMIN->value, 'web');
        $user_role = Role::findOrCreate(RoleEnum::USER->value, 'web');
        $client_role = Role::findOrCreate(RoleEnum::CLIENT->value, 'web');

        collect(PermissionEnum::cases())
            ->map(fn ($permission) => [
                'name' => $permission->value,
                'guard_name' => 'web',
            ])
            ->whenNotEmpty(function ($permissions) {
                Permission::upsert($permissions->toArray(), ['name', 'guard_name']);
            });

        // Clarity Scout permission (not in vendor PermissionEnum)
        Permission::findOrCreate('clarity-scout', 'web');

        // Otto chat (admin /app) — org plan is Clarity Pro Lite or higher; this permission is still required
        Permission::findOrCreate('use-otto', 'web');

        $admin_role->givePermissionTo(array_merge(RoleEnum::ADMIN->permissions(), RoleEnum::USER->permissions()));
        $user_role->givePermissionTo(RoleEnum::USER->permissions());
        //        $client_role->givePermissionTo(RoleEnum::CLIENT->permissions());

        $admin_role->givePermissionTo('use-otto');
        $user_role->givePermissionTo('use-otto');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->info('Roles and Permissions seeded!');
    }
}
