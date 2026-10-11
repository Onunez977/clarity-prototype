<?php

namespace Tests\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Filament\Pages\ClientAnalytics;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ClientAnalyticsTest extends TestCase
{
    #[Group('client-analytics')]
    public function test_impersonator_can_access_without_feature(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Admin User']]);

        $this->actingAs($user);

        $this->assertFalse(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));
        $this->assertTrue(ClientAnalytics::canAccess());

        Livewire::test(ClientAnalytics::class)
            ->assertSuccessful();
    }

    #[Group('client-analytics')]
    public function test_normal_user_cannot_access_without_feature(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        $this->assertFalse(session()->has('admin_impersonator'));
        $this->assertFalse(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));

        $this->get(ClientAnalytics::getUrl())
            ->assertRedirect();
    }

    #[Group('client-analytics')]
    public function test_normal_user_cannot_access_with_feature(): void
    {
        $user = $this->createUser();
        $this->makeOrgPro($user->org);

        $this->actingAs($user->refresh());

        $this->assertFalse(session()->has('admin_impersonator'));
        $this->assertTrue(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));

        $this->assertFalse(ClientAnalytics::canAccess());
    }

    #[Group('client-analytics')]
    public function test_admin_user_can_access_with_feature(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeOrgPro($user->org);

        $this->actingAs($user->refresh());

        $this->assertFalse(session()->has('admin_impersonator'));
        $this->assertTrue(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));

        Livewire::test(ClientAnalytics::class)
            ->assertSuccessful()
            ->assertSee('Client Analytics');
    }

    #[Group('client-analytics')]
    public function test_insight_lpr_employee_can_access(): void
    {
        $user = $this->createUser(['email' => 'test@insightlpr.com'], role: RoleEnum::ADMIN->value);

        $this->actingAs($user);

        $this->assertFalse(session()->has('admin_impersonator'));
        $this->assertTrue(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));

        Livewire::test(ClientAnalytics::class)
            ->assertSuccessful()
            ->assertSee('Client Analytics');
    }

    #[Group('client-analytics')]
    public function test_pro_enabled_organization_can_access(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeOrgPro($user->org);

        $this->actingAs($user->refresh());

        $this->assertFalse(session()->has('admin_impersonator'));
        $this->assertTrue(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));

        Livewire::test(ClientAnalytics::class)
            ->assertSuccessful()
            ->assertSee('Client Analytics');
    }

    #[Group('client-analytics')]
    public function test_impersonation_bypasses_pro_check(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        session(['admin_impersonator' => ['id' => 2, 'name' => 'Another Admin']]);

        $this->actingAs($user);

        $this->assertFalse(Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()));
        $this->assertTrue(session()->has('admin_impersonator'));

        Livewire::test(ClientAnalytics::class)
            ->assertSuccessful()
            ->assertSee('Client Analytics');
    }
}
