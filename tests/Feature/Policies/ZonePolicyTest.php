<?php

namespace Tests\Feature\Policies;

use App\Models\Org;
use App\Models\Zone;
use App\Policies\ZonePolicy;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ZonePolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_can_view_any_as_admin(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_view_any_as_non_admin(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser();

        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_can_view_zone_in_same_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $zone = $this->makeZone($user->org_id);

        $this->assertTrue($policy->view($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_view_zone_in_other_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $otherOrg = Org::factory()->create();
        $zone = $this->makeZone($otherOrg->id);

        $this->assertFalse($policy->view($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_can_create_as_admin(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        $this->assertTrue($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_create_as_non_admin(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser();

        $this->assertFalse($policy->create($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_can_update_zone_in_same_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $zone = $this->makeZone($user->org_id);

        $this->assertTrue($policy->update($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_update_zone_in_other_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $otherOrg = Org::factory()->create();
        $zone = $this->makeZone($otherOrg->id);

        $this->assertFalse($policy->update($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_update_as_non_admin(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser();
        $zone = $this->makeZone($user->org_id);

        $this->assertFalse($policy->update($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_can_delete_zone_in_same_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $zone = $this->makeZone($user->org_id);

        $this->assertTrue($policy->delete($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_delete_zone_in_other_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $otherOrg = Org::factory()->create();
        $zone = $this->makeZone($otherOrg->id);

        $this->assertFalse($policy->delete($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_can_restore_zone_in_same_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $zone = $this->makeZone($user->org_id);

        $this->assertTrue($policy->restore($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_restore_zone_in_other_org(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $otherOrg = Org::factory()->create();
        $zone = $this->makeZone($otherOrg->id);

        $this->assertFalse($policy->restore($user, $zone));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('zone')]
    public function test_cannot_force_delete_zone(): void
    {
        $policy = new ZonePolicy;
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $zone = $this->makeZone($user->org_id);

        $this->assertFalse($policy->forceDelete($user, $zone));
    }

    private function makeZone(int $orgId): Zone
    {
        return Zone::create([
            'org_id' => $orgId,
            'name' => 'Test Zone',
            'color' => '#2563eb',
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ]);
    }
}
