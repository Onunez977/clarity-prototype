<?php

namespace Tests\Filament\Concerns;

use App\Filament\Pages\AlertSettings;
use App\Filament\Pages\Subscription;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HasProFeatureTest extends TestCase
{
    #[Group('pro')]
    #[Group('concerns')]
    public function test_redirects_to_upgrade_page_when_feature_inactive(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(AlertSettings::class)
            ->assertRedirect(Subscription::getUrl());
    }

    #[Group('pro')]
    #[Group('concerns')]
    public function test_does_not_redirect_when_admin_impersonating(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        session()->put('admin_impersonator', true);

        Livewire::test(AlertSettings::class)
            ->assertOk();
    }

    #[Group('pro')]
    #[Group('concerns')]
    public function test_client_role_user_is_not_redirected_to_subscription(): void
    {
        $user = $this->createUser(role: RoleEnum::CLIENT->value);

        $this->actingAs($user);

        Livewire::test(AlertSettings::class)
            ->assertOk();
    }
}
