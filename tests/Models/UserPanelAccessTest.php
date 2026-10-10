<?php

namespace Tests\Models;

use App\Models\ClientOrgAccess;
use App\Models\Org;
use App\Models\User;
use Filament\Facades\Filament;
use InsightLPR\BlackAdam\Enum\OrgType;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class UserPanelAccessTest extends TestCase
{
    #[Group('panel-access')]
    public function test_repo_org_with_hotlist_enabled_can_access_app_panel_only(): void
    {
        $user = $this->createUser();

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('client')));
    }

    #[Group('panel-access')]
    public function test_repo_org_with_hotlist_disabled_cannot_access_app_panel(): void
    {
        $user = $this->createUser();

        $user->org->update(['enable_genetech_hotlist' => false]);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('app')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('client')));
    }

    #[Group('panel-access')]
    public function test_client_org_with_enabled_portal_and_active_grant_can_access_client_panel_only(): void
    {
        $user = $this->createClientPortalUser();

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('client')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('app')));
    }

    #[Group('panel-access')]
    public function test_client_org_with_only_revoked_grants_cannot_access_client_panel(): void
    {
        $user = $this->createClientPortalUser(withAccessGrant: false);

        ClientOrgAccess::factory()
            ->revoked()
            ->create([
                'client_org_id' => $user->org_id,
                'repo_org_id' => Org::factory()->create()->id,
            ]);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('client')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('app')));
    }

    #[Group('panel-access')]
    public function test_client_org_with_disabled_portal_cannot_access_client_panel(): void
    {
        $user = $this->createClientPortalUser(portalEnabled: false);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('client')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('app')));
    }

    #[Group('panel-access')]
    public function test_client_role_without_client_org_access_does_not_grant_client_panel_access(): void
    {
        $user = $this->createUser(role: RoleEnum::CLIENT->value);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('client')));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
    }

    #[Group('panel-access')]
    public function test_non_repo_and_non_client_org_cannot_access_any_panel(): void
    {
        $org = Org::factory()->create([
            'type' => OrgType::LienHolder->value,
            'enable_genetech_hotlist' => true,
        ]);

        $user = User::factory()
            ->forOrg($org)
            ->create();

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('client')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('app')));
    }
}
