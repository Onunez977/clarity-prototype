<?php

namespace Tests\Feature\Policies;

use App\Models\RecoveriesKitUserScansReport;
use App\Policies\RecoveriesKitUserScansReportPolicy;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RecoveriesKitUserScansReportPolicyTest extends TestCase
{
    #[Group('policy')]
    #[Group('policies')]
    #[Group('recoveries')]
    public function test_can_view_any(): void
    {
        $policy = new RecoveriesKitUserScansReportPolicy;

        $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
        $this->assertTrue($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('recoveries')]
    public function test_cannot_view_any(): void
    {
        $policy = new RecoveriesKitUserScansReportPolicy;

        $user = $this->createUser(permissions: []);
        $this->assertFalse($policy->viewAny($user));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('recoveries')]
    public function test_can_view_same_org_report(): void
    {
        $policy = new RecoveriesKitUserScansReportPolicy;

        $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
        $report = RecoveriesKitUserScansReport::factory()->make([
            'org_id' => $user->org_id,
        ]);

        $this->assertTrue($policy->view($user, $report));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('recoveries')]
    public function test_cannot_view_other_org_report(): void
    {
        $policy = new RecoveriesKitUserScansReportPolicy;

        $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
        $report = RecoveriesKitUserScansReport::factory()->make();

        $this->assertFalse($policy->view($user, $report));
    }

    #[Group('policy')]
    #[Group('policies')]
    #[Group('recoveries')]
    public function test_cannot_crud(): void
    {
        $policy = new RecoveriesKitUserScansReportPolicy;

        $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
        $report = RecoveriesKitUserScansReport::factory()->make();

        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $report));
        $this->assertFalse($policy->delete($user, $report));
        $this->assertFalse($policy->restore($user, $report));
        $this->assertFalse($policy->forceDelete($user, $report));
    }
}
