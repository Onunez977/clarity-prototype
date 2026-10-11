<?php

namespace Tests\Filament\Pages;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\HitsResource;
use App\Models\Role;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    #[Group('pages')]
    #[Group('dashboard')]
    public function test_page_and_navigation_are_labelled_company_metrics(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $this->get(route('filament.app.pages.dashboard'))
            ->assertSee('Company Metrics')
            ->assertDontSee('>Dashboard<', false);

        $this->assertSame('Company Metrics', Dashboard::getNavigationLabel());
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_anonymous_user_cannot_see_dashboard(): void
    {
        $this->get(route('filament.app.pages.dashboard'))
            ->assertStatus(302)
            ->assertRedirectToRoute('filament.app.auth.login');
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_admin_user_can_see_dashboard(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $this->get(route('filament.app.pages.dashboard'))
            ->assertStatus(200);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_normal_user_can_see_dashboard(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::USER->value)
        );

        $this->get(route('filament.app.pages.dashboard'))
            ->assertStatus(200);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_client_user_cannot_see_dashboard(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::CLIENT->value)
        );

        $this->get(route('filament.app.pages.dashboard'))
            ->assertRedirect();
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_custom_role_user_with_other_permissions_can_get_redirected(): void
    {
        $role = Role::create(['name' => fake()->words(3, true)]);

        $this->actingAs(
            $this->createUser(role: $role->name, permissions: [PermissionEnum::ViewAny_Hit])
        );

        $this->get(route('filament.app.pages.dashboard'))
            ->assertRedirect(HitsResource::getUrl());
    }
}
