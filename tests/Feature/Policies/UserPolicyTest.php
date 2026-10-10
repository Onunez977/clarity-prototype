<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_view_any(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_view_any(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_view_self(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertTrue($policy->view($user, $user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_view_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertTrue($policy->view($user, User::factory()->make([
            'org_id' => $user->org_id,
        ])));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_view_other_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->view($user, User::factory()->make()));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_create(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertTrue($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_create(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_update_self(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertTrue($policy->update($user, $user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_update_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertTrue($policy->update($user, User::factory()->make([
            'org_id' => $user->org_id,
        ])));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_update_other_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->update($user, User::factory()->make()));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_delete_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertTrue($policy->delete($user, User::factory()->make([
            'org_id' => $user->org_id,
        ])));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_delete_self(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertFalse($policy->delete($user, $user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_delete_other_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->delete($user, User::factory()->make()));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_can_restore_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser(permissions: PermissionEnum::MANAGE_ACCOUNT_USERS->value);
        $this->assertTrue($policy->restore($user, User::factory()->make([
            'org_id' => $user->org_id,
        ])));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_restore_other_org_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->restore($user, User::factory()->make()));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('user')]
    public function test_cannot_force_delete_user(): void
    {
        $policy = new UserPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->forceDelete($user, User::factory()->make()));
    }
}
