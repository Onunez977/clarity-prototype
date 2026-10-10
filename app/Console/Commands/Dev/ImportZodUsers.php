<?php

namespace App\Console\Commands\Dev;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * @codeCoverageIgnore
 */
class ImportZodUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dev:users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports the users from the Zod database';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // throw_if(app()->environment('production'), new \Exception('This command can only be run in local environment'));

        $this->importUsersFromZod();
        //       $this->importRolesFromGraphQl();
        //       $this->importPermissionsFromGraphQl();
        //       $this->importRolePermissionsFromGraphQl();
        $this->importUserRolesFromGraphQl();
    }

    public function importRolesFromGraphQl(): void
    {
        DB::connection('graphql')
            ->table('public.roles')
            ->orderBy('id')
            ->each(function ($role) {
                DB::connection('platform')
                    ->table('platform.roles')->updateOrInsert([
                        'id' => $role->id,
                    ], [
                        'name' => $role->name,
                        'guard_name' => 'web',
                        'org_id' => $role->org_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function importPermissionsFromGraphQl(): void
    {
        DB::connection('graphql')
            ->table('public.permissions')
            ->orderBy('id')
            ->each(function ($permission) {
                DB::connection('platform')->table('platform.permissions')->updateOrInsert([
                    'id' => $permission->id,
                ], [
                    'name' => $permission->name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function importRolePermissionsFromGraphQl(): void
    {

        DB::connection('graphql')
            ->table('public.role_has_permissions')
            ->orderBy('permission_id')
            ->orderBy('role_id')
            ->each(function ($rolePermission) {
                DB::connection('platform')
                    ->table('platform.role_has_permissions')->updateOrInsert([
                        'permission_id' => $rolePermission->permission_id,
                        'role_id' => $rolePermission->role_id,
                    ]);
            });
    }

    public function importUserRolesFromGraphQl(): void
    {
        DB::connection('graphql')
            ->table('public.model_has_roles')
            ->orderBy('role_id')
            ->orderBy('model_id')
            ->orderBy('org_id')
            ->orderBy('model_type')

            ->each(function ($userRole) {
                DB::connection('platform')
                    ->table('platform.model_has_roles')->updateOrInsert([
                        'role_id' => $userRole->role_id,
                        'model_type' => User::class,
                        'model_id' => $userRole->model_id,
                        'org_id' => $userRole->org_id,
                    ]);
            });
    }

    public function importUsersFromZod(): void
    {
        DB::connection('zod')->unprepared('

        INSERT INTO platform.users (id, name, email, org_id, deleted_at, dti, password, created_at, updated_at)
select users.id,
       username as name,
       username as email,
       org_id,
       deleted_at,
       dti,
       password,
       now(),
         now()
FROM public.users
--LEFT JOIN public.org_meta ON org_meta.id = users.org_id
--where org_meta.properties->>\'is_system_of_record\' is null
--or org_meta.properties->>\'is_system_of_record\' <> \'true\'

on conflict (id) do update set
       name = excluded.name,
       email = excluded.email,
       org_id = excluded.org_id,
       deleted_at = excluded.deleted_at,
       dti = excluded.dti,
       password = excluded.password,
       updated_at = now();

        ');

    }
}
