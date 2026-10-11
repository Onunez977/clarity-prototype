<?php

namespace Tests\Filament;

use App\Filament\Client\Resources\ClientSearchScansResource;
use App\Filament\Client\Resources\ClientSearchScansResource\Pages\ListClientSearchScans;
use App\Filament\Pages\Dashboard;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RedirectsToFilamentTest extends TestCase
{
    #[Group('redirects')]
    public function test_index_redirects_to_filament_login(): void
    {
        $this->get('/')
            ->assertStatus(302)
            ->assertRedirectToRoute('filament.app.auth.login');
    }

    #[Group('redirects')]
    public function test_login_redirects_to_dashboard(): void
    {
        $this->actingAs(
            $this->createUser(role: RoleEnum::ADMIN->value)
        );

        $this->get(route('filament.app.auth.login'))
            ->assertStatus(302)
            ->assertRedirect(Dashboard::getUrl());
    }

    #[Group('redirects')]
    public function test_login_redirects_to_client_search_for_client_user(): void
    {
        $this->actingAs(
            $this->createClientPortalUser()
        );

        $this->get(route('filament.app.auth.login'))
            ->assertStatus(302)
            ->assertRedirect(ClientSearchScansResource::getUrl(panel: 'client'));
    }

    #[Group('redirects')]
    public function test_login_redirects_to_app_dashboard_for_repo_user(): void
    {
        $this->actingAs(
            $this->createUser()
        );

        $this->get(route('filament.app.auth.login'))
            ->assertStatus(302)
            ->assertRedirect(Dashboard::getUrl(panel: 'app'));
    }

    #[Group('redirects')]
    public function test_client_resource_access_works_without_client_role(): void
    {
        $user = $this->createClientPortalUser();

        $this->actingAs($user);

        $this->assertFalse($user->hasRole(RoleEnum::CLIENT->value));

        Livewire::test(ListClientSearchScans::class)
            ->assertSuccessful();

        $this->get(ClientSearchScansResource::getUrl(panel: 'client'))
            ->assertSuccessful();
    }

    #[Group('redirects')]
    public function test_billing_redirects_pay_portal(): void
    {
        $this->get(route('billing'))
            ->assertStatus(302)
            ->assertRedirect('https://customer.billergenie.com/Insight-LPR-LLC/Account/Login/13651');
    }
}
