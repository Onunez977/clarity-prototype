<?php

namespace Tests\Feature\Policies;

use App\Models\Hit;
use App\Models\Kit;
use App\Policies\HitPolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HitPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_can_view_any(): void
    {
        $policy = new HitPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_cannot_view_any(): void
    {
        $policy = new HitPolicy;

        $user = $this->createUser(permissions: []);
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_can_view_same_org_hit(): void
    {
        $policy = new HitPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);

        $kit = Kit::factory()->create([
            'org_id' => $user->org_id,
        ]);

        $hit = Hit::factory()->make([
            'kit_id' => $kit->id,
        ]);

        $this->assertTrue($policy->view($user, $hit));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_cannot_view_same_org_hit_without_permission(): void
    {
        $policy = new HitPolicy;

        $user = $this->createUser(permissions: []);
        $hit = Hit::factory()->make();

        $this->assertFalse($policy->view($user, $hit));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_cannot_view_other_org_hit(): void
    {
        $policy = new HitPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $hit = Hit::factory()->make();

        $this->assertFalse($policy->view($user, $hit));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('hit')]
    #[Group('hits')]
    public function test_cannot_crud(): void
    {
        $policy = new HitPolicy;

        $user = $this->createUser();

        $kit = Kit::factory()->create([
            'org_id' => $user->org_id,
        ]);

        $hit = Hit::factory()->make([
            'kit_id' => $kit->id,
        ]);

        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $hit));
        $this->assertFalse($policy->delete($user, $hit));
        $this->assertFalse($policy->restore($user, $hit));
        $this->assertFalse($policy->forceDelete($user, $hit));
    }
}
