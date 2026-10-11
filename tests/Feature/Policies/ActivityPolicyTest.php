<?php

namespace Tests\Feature\Policies;

use App\Policies\ActivityPolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('activity')]
    #[Group('activities')]
    public function test_can_view_any(): void
    {
        $policy = new ActivityPolicy;

        $adminUser = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->assertTrue($policy->viewAny($adminUser));

        $permissionUser = $this->createUser(permissions: PermissionEnum::VIEW_ACCOUNT_ACTIVITY->value);
        $this->assertTrue($policy->viewAny($permissionUser));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('activity')]
    #[Group('activities')]
    public function test_cannot_view_any(): void
    {
        $policy = new ActivityPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('activity')]
    #[Group('activities')]
    public function test_cannot_crud(): void
    {
        $policy = new ActivityPolicy;

        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $activity = Activity::make();

        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $activity));
        $this->assertFalse($policy->delete($user, $activity));
        $this->assertFalse($policy->restore($user, $activity));
        $this->assertFalse($policy->forceDelete($user, $activity));
    }
}
