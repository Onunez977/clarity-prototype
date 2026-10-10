<?php

namespace Tests\Feature\Policies;

use App\Models\Scan;
use App\Policies\ScanPolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ScanPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('scans')]
    public function test_can_view_any(): void
    {
        $policy = new ScanPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('scans')]
    public function test_cannot_view_any(): void
    {
        $policy = new ScanPolicy;

        $user = $this->createUser();
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('scans')]
    public function test_can_view_org_scan(): void
    {
        $policy = new ScanPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $scan = Scan::factory()
            ->forOrg($user->org)
            ->make();

        $this->assertTrue($policy->view($user, $scan));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('scans')]
    public function test_cannot_view_other_org_scan(): void
    {
        $policy = new ScanPolicy;

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $scan = Scan::factory()->make();

        $this->assertFalse($policy->view($user, $scan));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('scans')]
    public function test_cannot_view_org_scan_without_permission(): void
    {
        $policy = new ScanPolicy;

        $user = $this->createUser(permissions: []);
        $scan = Scan::factory()
            ->forOrg($user->org)
            ->make();

        $this->assertFalse($policy->view($user, $scan));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('scans')]
    public function test_cannot_crud(): void
    {
        $policy = new ScanPolicy;

        $user = $this->createUser();
        $scan = Scan::factory()
            ->forOrg($user->org)
            ->make();

        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $scan));
        $this->assertFalse($policy->delete($user, $scan));
        $this->assertFalse($policy->restore($user, $scan));
        $this->assertFalse($policy->forceDelete($user, $scan));
    }
}
