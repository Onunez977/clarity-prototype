<?php

namespace Tests\Filament\Pages;

use App\Filament\Pages\Roles;
use App\Models\Org;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RolesPageTest extends TestCase
{
    #[Group('pages')]
    #[Group('roles')]
    public function test_render(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $this->get(route('filament.app.pages.roles'))
            ->assertSuccessful();
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_doesnt_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        $this->get(route('filament.app.pages.roles'))
            ->assertForbidden();
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_can_see_roles(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
        ]);

        $this->get(route('filament.app.pages.roles'))
            ->assertSuccessful()
            ->assertSeeText($role->name);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_can_see_org_roles(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $this->get(route('filament.app.pages.roles'))
            ->assertSuccessful()
            ->assertSeeText($role->name);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_see_other_org_roles(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => Org::factory()->create()->id,
        ]);

        $this->get(route('filament.app.pages.roles'))
            ->assertSuccessful()
            ->assertDontSeeText($role->name);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_see_other_org_users(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
        ]);

        $roleUser = User::factory()->create();
        $roleUser->assignRole($role->name);

        $this->get(route('filament.app.pages.roles'))
            ->assertSuccessful()
            ->assertSeeText($role->name)
            ->assertDontSeeText($roleUser->email);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_can_delete_custom_org_role(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $roleUser = User::factory()->create();
        $roleUser->assignRole($role->name);

        Livewire::test(Roles::class)
            ->assertSee('wire:click="deleteRole(\''.$role->id.'\')"', false)
            ->call('deleteRole', $role->id)
            ->assertNotified('Role deleted successfully.');

        // Reset to roled user.
        setPermissionsTeamId($roleUser->org_id);

        $roleUser->refresh();

        $this->assertFalse($roleUser->hasRole($role));
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_delete_custom_role_from_other_org(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => Org::factory()->create()->id,
        ]);

        Livewire::test(Roles::class)
            ->assertDontSee('wire:click="deleteRole(\''.$role->id.'\')"', false)
            ->call('deleteRole', $role->id)
            ->assertNotNotified('Role deleted successfully.');
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_can_delete_user_from_role(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $roleUser = User::factory()->create();
        $roleUser->assignRole($role->name);

        Livewire::test(Roles::class)
            ->call('removeUserFromRole', $role->id, $roleUser->id)
            ->assertNotified('User removed from Role.');

        // Reset to related user.
        setPermissionsTeamId($roleUser->org_id);

        $roleUser->refresh();

        $this->assertFalse($roleUser->hasRole($role));
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_delete_user_from_role_they_dont_have(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $roleUser = User::factory()->create();

        $this->assertCount(0, $roleUser->roles);

        Livewire::test(Roles::class)
            ->call('removeUserFromRole', $role->id, $roleUser->id)
            ->assertNotified('Unable to complete action.');

        // Reset to roled user.
        setPermissionsTeamId($roleUser->org_id);

        $roleUser->refresh();

        $this->assertCount(0, $roleUser->roles);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_delete_user_from_role_without_permissions(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $roleUser = User::factory()->create();
        setPermissionsTeamId($roleUser->org_id);

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $roleUser->org_id,
        ]);

        $roleUser->assignRole($role->name);

        // Reset to main, auth user.
        setPermissionsTeamId($user->org_id);

        Livewire::test(Roles::class)
            ->call('removeUserFromRole', $role->id, $roleUser->id);

        // Reset to roled user.
        setPermissionsTeamId($roleUser->org_id);

        $roleUser->refresh();

        $this->assertTrue($roleUser->hasRole($role));
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_can_update_user_to_default_role_on_deletion(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $roleUser = User::factory()
            ->forOrg($user->org)
            ->create();

        $roleUser->assignRole($role->name);

        Livewire::test(Roles::class)
            ->call('deleteRole', $role->id)
            ->assertNotified('Role deleted successfully.');

        // Reset to roled user.
        setPermissionsTeamId($roleUser->org_id);

        $roleUser->refresh();

        $this->assertFalse($roleUser->hasRole($role));
        $this->assertTrue($roleUser->hasRole(RoleEnum::USER->value));
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_update_user_to_default_role_on_deletion_if_they_have_more_roles(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role1 = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $role2 = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $roleUser = User::factory()
            ->forOrg($user->org)
            ->create();

        $roleUser->assignRole($role1->name);
        $roleUser->assignRole($role2->name);

        Livewire::test(Roles::class)
            ->call('deleteRole', $role1->id)
            ->assertNotified('Role deleted successfully.');

        // Reset to roled user.
        setPermissionsTeamId($roleUser->org_id);

        $roleUser->refresh();

        $this->assertFalse($roleUser->hasRole($role1));
        $this->assertTrue($roleUser->hasRole($role2));
        $this->assertFalse($roleUser->hasRole(RoleEnum::USER->value));
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_can_add_permission_to_custom_role(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $permission = Permission::firstWhere('name', PermissionEnum::ViewAny_Hit->value);

        $this->assertCount(0, $role->permissions);

        Livewire::test(Roles::class)
            ->assertSuccessful()
            ->call('toggleRolePermission', $role->id, $permission->id)
            ->assertNotified('Permission added to Role.');

        $role->refresh();

        $this->assertCount(1, $role->permissions);
        $this->assertEquals($permission->name, $role->permissions->first()->name);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_can_remove_permission_to_custom_role(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $permission = Permission::firstWhere('name', PermissionEnum::ViewAny_Hit->value);
        $role->givePermissionTo($permission->name);

        $role->refresh();

        $this->assertCount(1, $role->permissions);

        Livewire::test(Roles::class)
            ->assertSuccessful()
            ->call('toggleRolePermission', $role->id, $permission->id);

        $role->refresh();

        $this->assertCount(0, $role->permissions);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_add_permission_to_base_role(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => null,
        ]);

        $permission = Permission::firstWhere('name', PermissionEnum::ViewAny_Hit->value);

        $this->assertCount(0, $role->permissions);

        Livewire::test(Roles::class)
            ->assertSuccessful()
            ->call('toggleRolePermission', $role->id, $permission->id)
            ->assertNotNotified('Permission added to Role.');

        $role->refresh();

        $this->assertCount(0, $role->permissions);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_remove_permission_to_base_role(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => null,
        ]);

        $permission = Permission::firstWhere('name', PermissionEnum::ViewAny_Hit->value);
        $role->givePermissionTo($permission->name);

        $role->refresh();

        $this->assertCount(1, $role->permissions);

        Livewire::test(Roles::class)
            ->assertSuccessful()
            ->call('toggleRolePermission', $role->id, $permission->id)
            ->assertNotNotified('Permission removed to Role.');

        $role->refresh();

        $this->assertCount(1, $role->permissions);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_add_permission_to_other_org_role(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => Org::factory()->create()->id,
        ]);

        $permission = Permission::firstWhere('name', PermissionEnum::ViewAny_Hit->value);

        $this->assertCount(0, $role->permissions);

        Livewire::test(Roles::class)
            ->assertSuccessful()
            ->call('toggleRolePermission', $role->id, $permission->id)
            ->assertNotNotified('Permission added to Role.');

        $role->refresh();

        $this->assertCount(0, $role->permissions);
    }

    #[Group('pages')]
    #[Group('roles')]
    public function test_cannot_remove_permission_to_other_org_role(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value)
        );

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => Org::factory()->create()->id,
        ]);

        $permission = Permission::firstWhere('name', PermissionEnum::ViewAny_Hit->value);
        $role->givePermissionTo($permission->name);

        $role->refresh();

        $this->assertCount(1, $role->permissions);

        Livewire::test(Roles::class)
            ->assertSuccessful()
            ->call('toggleRolePermission', $role->id, $permission->id)
            ->assertNotNotified('Permission removed to Role.');

        $role->refresh();

        $this->assertCount(1, $role->permissions);
    }
}
