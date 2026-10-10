<?php

namespace Tests\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\IndividualClientAnalytics;
use App\Models\Hotlist;
use App\Models\Org;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class IndividualClientAnalyticsTest extends TestCase
{
    protected function setupIndividualDashboard(): array
    {
        $user = $this->createUser();
        $this->makeOrgPro($user->org);

        $clientOrg = Org::factory()->create(['name' => 'Test Client']);
        Hotlist::factory()->forOrg($user->org)->create(['client_org_id' => $clientOrg->id]);

        return [$user, $clientOrg];
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_user_can_access_valid_client(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful()
            ->assertSee('Test Client');
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_user_redirected_for_invalid_client(): void
    {
        [$user] = $this->setupIndividualDashboard();

        $invalidClient = Org::factory()->create(['name' => 'Invalid Client']);

        $this->actingAs($user);

        $this->get(IndividualClientAnalytics::getUrl(parameters: ['clientId' => $invalidClient->id]))
            ->assertRedirect(Dashboard::getUrl());
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_user_redirected_for_non_existent_client(): void
    {
        [$user] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        $this->get(IndividualClientAnalytics::getUrl(parameters: ['clientId' => 99999]))
            ->assertRedirect(Dashboard::getUrl());
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_impersonation_bypasses_pro_check(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $clientOrg = Org::factory()->create(['name' => 'Test Client']);
        Hotlist::factory()->forOrg($user->org)->create(['client_org_id' => $clientOrg->id]);

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Admin User']]);

        $this->actingAs($user);

        $this->assertFalse(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));
        $this->assertTrue(session()->has('admin_impersonator'));
        $this->assertTrue(IndividualClientAnalytics::canAccess());

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful();
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_page_title_shows_client_name(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful()
            ->assertSee('Test Client');
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_get_widget_data_passes_client_org_id(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful()
            ->call('getWidgetData')
            ->assertSet('clientId', $clientOrg->id);

        // Verify widget data contains clientOrgId
        $component = Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id]);
        $widgetData = $component->instance()->getWidgetData();

        $this->assertArrayHasKey('client_org_id', $widgetData);
        $this->assertEquals($clientOrg->id, $widgetData['client_org_id']);
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_get_widget_data_passes_default_date_type(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful()
            ->assertSet('clientId', $clientOrg->id);

        // Verify widget data contains dateType
        $component = Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id]);
        $widgetData = $component->instance()->getWidgetData();

        $this->assertArrayHasKey('date_type', $widgetData);
        $this->assertEquals('this_month', $widgetData['date_type']);
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_route_path_includes_client_id_parameter(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        $url = IndividualClientAnalytics::getUrl(parameters: ['clientId' => $clientOrg->id]);

        $this->assertStringContainsString('/client-dashboard/', $url);
        $this->assertStringContainsString((string) $clientOrg->id, $url);
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_mount_sets_client_id_from_route(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful()
            ->assertSet('clientId', $clientOrg->id);
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_mount_dispatches_refresh_dashboard_event_with_client_context(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful()
            ->assertSet('clientId', $clientOrg->id)
            ->assertDispatched('refresh-dashboard',
                branch_id: null,
                start_date: null,
                end_date: null,
                date_type: 'this_month',
                client_org_id: $clientOrg->id
            );
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_updated_filters_dispatches_event_with_new_filters(): void
    {
        [$user, $clientOrg] = $this->setupIndividualDashboard();

        $this->actingAs($user);

        Livewire::test(IndividualClientAnalytics::class, ['clientId' => $clientOrg->id])
            ->assertSuccessful()
            ->set('filters.date_toggles', 'ytd')
            ->assertSet('filters.date_toggles', 'ytd')
            ->assertDispatched('refresh-dashboard', date_type: 'ytd', client_org_id: $clientOrg->id)
            ->assertSuccessful();
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_can_access_checks_feature_flag(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeOrgPro($user->org);

        $this->actingAs($user->refresh());

        $this->assertTrue(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));
        $this->assertTrue(IndividualClientAnalytics::canAccess());
    }

    #[Group('client-analytics')]
    #[Group('pages')]
    public function test_can_access_allows_impersonators(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Admin User']]);

        $this->actingAs($user);

        $this->assertFalse(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));
        $this->assertTrue(session()->has('admin_impersonator'));
        $this->assertTrue(IndividualClientAnalytics::canAccess());
    }
}
