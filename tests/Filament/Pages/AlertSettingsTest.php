<?php

namespace Tests\Filament\Pages;

use App\Filament\Pages\AlertSettings;
use App\Models\AlertSetting;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AlertSettingsTest extends TestCase
{
    #[Group('pages')]
    #[Group('alerts')]
    #[Group('alerts-settings')]
    public function test_render(): void
    {
        $this->actingAs(
            $this->createUser(pro: true)
        );

        $this->get(route('filament.app.pages.alert-settings'))
            ->assertSuccessful()
            ->assertSeeText('My Alert Settings')
            ->assertSeeText('No alert settings');
    }

    #[Group('pages')]
    #[Group('alerts')]
    #[Group('alerts-settings')]
    public function test_does_not_render_for_non_pro_users(): void
    {
        $this->actingAs(
            $this->createUser(pro: false)
        );

        $this->get(route('filament.app.pages.alert-settings'))
            ->assertRedirect();
    }

    #[Group('pages')]
    #[Group('alerts')]
    #[Group('alerts-settings')]
    public function test_render_alert_settings(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true)
        );

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->smsNotification([$user->mobile_phone])
            ->emailNotification([$user->email])
            ->create();

        $this->get(route('filament.app.pages.alert-settings'))
            ->assertSuccessful()
            ->assertSeeText('My Alert Settings')
            ->assertSeeText($alert->name)
            ->assertSeeText('Sms: 1 | Emails: 1 | Slack: 0');
    }

    #[Group('pages')]
    #[Group('alerts')]
    #[Group('alerts-settings')]
    public function test_renders_a_row_with_an_unknown_type_code(): void
    {
        $this->actingAs(
            $user = $this->createUser(pro: true)
        );

        $alert = AlertSetting::factory()
            ->forUser($user)
            ->forTypes(types: ['ZZ', 'L'])
            ->create();

        Livewire::test(AlertSettings::class)
            ->assertSuccessful()
            ->assertSee($alert->name);
    }
}
