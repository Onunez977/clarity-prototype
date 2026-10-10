<?php

namespace Tests\Feature\Policies;

use App\Models\Hotlist;
use App\Policies\HotlistPolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HotlistPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_can_view_any(): void
    {
        $policy = new HotlistPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hotlist->value);
        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_cannot_view_any(): void
    {
        $policy = new HotlistPolicy;

        $user = $this->createUser(permissions: []);
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_can_view_same_org_hotlist(): void
    {
        $policy = new HotlistPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hotlist->value);
        $hotlist = Hotlist::factory()->make([
            'org_id' => $user->org_id,
        ]);

        $this->assertTrue($policy->view($user, $hotlist));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_cannot_view_org_hotlist_without_permission(): void
    {
        $policy = new HotlistPolicy;

        $user = $this->createUser(permissions: []);
        $hotlist = Hotlist::factory()->make([
            'org_id' => $user->org_id,
        ]);

        $this->assertFalse($policy->view($user, $hotlist));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_cannot_view_other_org_hotlist(): void
    {
        $policy = new HotlistPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $hotlist = Hotlist::factory()->make();

        $this->assertFalse($policy->view($user, $hotlist));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_cannot_crud(): void
    {
        $policy = new HotlistPolicy;

        $user = $this->createUser();
        $hotlist = Hotlist::factory()->make([
            'org_id' => $user->org_id,
        ]);

        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $hotlist));
        $this->assertFalse($policy->delete($user, $hotlist));
        $this->assertFalse($policy->restore($user, $hotlist));
        $this->assertFalse($policy->forceDelete($user, $hotlist));
    }
}
