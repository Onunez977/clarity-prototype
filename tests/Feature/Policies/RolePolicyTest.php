<?php

namespace Tests\Feature\Policies;

use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RolePolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_can_view_any(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_view_any(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles1')]
    public function test_can_view(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => null,
        ]);

        $this->assertTrue($policy->view($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_view(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: []);
        $role = Role::create([
            'name' => fake()->words(3, true),
        ]);

        $this->assertFalse($policy->view($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_can_view_org_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $this->assertTrue($policy->view($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_view_other_org_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => Org::factory()->create()->id,
        ]);

        $this->assertFalse($policy->view($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_can_create(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $this->assertTrue($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_create(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: []);
        $this->assertFalse($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_can_update(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => null,
        ]);

        $this->assertTrue($policy->update($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_update(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: []);
        $role = Role::create([
            'name' => fake()->words(3, true),
        ]);

        $this->assertFalse($policy->update($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_can_update_org_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $this->assertTrue($policy->update($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_update_other_org_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => Org::factory()->create()->id,
        ]);

        $this->assertFalse($policy->update($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_can_delete_org_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $this->assertTrue($policy->delete($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_delete_other_org_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => Org::factory()->create()->id,
        ]);

        $this->assertFalse($policy->delete($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_restore_any_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $this->assertFalse($policy->restore($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_force_delete_any_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);
        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $this->assertFalse($policy->forceDelete($user, $role));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_can_remove_user_from_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $roleUser = User::factory()->create([
            'org_id' => $user->org_id,
        ]);
        $roleUser->assignRole($role);

        $this->assertTrue($policy->removeUser($user, $role, $roleUser));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_other_org_user_from_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $roleUser = User::factory()->create();
        $roleUser->assignRole($role);

        $this->assertFalse($policy->removeUser($user, $role, $roleUser));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('roles')]
    public function test_cannot_remove_self_from_role(): void
    {
        $policy = new RolePolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_ROLES->value);

        $role = Role::create([
            'name' => fake()->words(3, true),
            'org_id' => $user->org_id,
        ]);

        $user->assignRole($role);

        $this->assertFalse($policy->removeUser($user, $role, $user));
    }
}
