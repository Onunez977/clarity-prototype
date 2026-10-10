<?php

namespace Tests\Feature\Policies;

use App\Models\Kit;
use App\Models\MissedOpportunity;
use App\Policies\MissedOpportunityPolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class MissedOpportunityPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_can_view_any(): void
    {
        $policy = new MissedOpportunityPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value, pro: true);
        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_view_any_without_permissions(): void
    {
        $policy = new MissedOpportunityPolicy;

        $user = $this->createUser(permissions: [], pro: true);
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_can_view_same_org_hit(): void
    {
        $policy = new MissedOpportunityPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value, pro: true);

        $kit = Kit::factory()->create([
            'org_id' => $user->org_id,
        ]);

        $hit = MissedOpportunity::factory()->make([
            'kit_id' => $kit->id,
        ]);

        $this->assertTrue($policy->view($user, $hit));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_view_same_org_hit_without_permission(): void
    {
        $policy = new MissedOpportunityPolicy;

        $user = $this->createUser(permissions: [], pro: true);

        $hit = MissedOpportunity::factory()->make();

        $this->assertFalse($policy->view($user, $hit));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_view_same_org_hit_without_being_pro(): void
    {
        $policy = new MissedOpportunityPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value, pro: false);

        $kit = Kit::factory()->create([
            'org_id' => $user->org_id,
        ]);

        $hit = MissedOpportunity::factory()->make([
            'kit_id' => $kit->id,
        ]);

        $this->assertFalse($policy->view($user, $hit));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_view_other_org_hit(): void
    {
        $policy = new MissedOpportunityPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value, pro: true);

        $hit = MissedOpportunity::factory()->make();

        $this->assertFalse($policy->view($user, $hit));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('missed-opportunity')]
    #[Group('missed-opportunities')]
    public function test_cannot_crud(): void
    {
        $policy = new MissedOpportunityPolicy;

        $user = $this->createUser();

        $kit = Kit::factory()->create([
            'org_id' => $user->org_id,
        ]);

        $hit = MissedOpportunity::factory()->make([
            'kit_id' => $kit->id,
        ]);

        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $hit));
        $this->assertFalse($policy->delete($user, $hit));
        $this->assertFalse($policy->restore($user, $hit));
        $this->assertFalse($policy->forceDelete($user, $hit));
    }
}
