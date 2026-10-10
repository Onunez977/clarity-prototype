<?php

namespace Tests\Feature\Policies;

use App\Models\AlertSetting;
use App\Policies\AlertSettingPolicy;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AlertSettingPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_can_view_any(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser();

        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_can_view_own(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: true);

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create();

        $this->assertTrue($policy->view($user, $alert));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_view_own_without_pro(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: false);

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create();

        $this->assertFalse($policy->view($user, $alert));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_view_other_user(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: true);

        $this->assertFalse($policy->view($user, AlertSetting::factory()->make()));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_can_create(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: true);

        $this->assertTrue($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_create_without_pro(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: false);

        $this->assertFalse($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_can_update_own(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: true);

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create();

        $this->assertTrue($policy->update($user, $alert));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_update_own_without_pro(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: false);

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create();

        $this->assertFalse($policy->update($user, $alert));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_update_other_user(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: true);

        $this->assertFalse($policy->update($user, AlertSetting::factory()->make()));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_can_delete_own(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: true);

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create();

        $this->assertTrue($policy->delete($user, $alert));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_delete_own_without_pro(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: false);

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->create();

        $this->assertFalse($policy->delete($user, $alert));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_delete_other_user(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser(pro: true);

        $alert = AlertSetting::factory()->create();

        $this->assertFalse($policy->delete($user, $alert));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_restore(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser();

        $this->assertFalse($policy->restore($user, AlertSetting::factory()->make()));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('alert-settings')]
    public function test_cannot_force_delete(): void
    {
        $policy = new AlertSettingPolicy;

        $user = $this->createUser();

        $this->assertFalse($policy->forceDelete($user, AlertSetting::factory()->make()));
    }
}
