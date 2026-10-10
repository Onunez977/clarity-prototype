<?php

namespace Tests\Filament\Widgets\AlertSettings;

use App\Filament\Widgets\AlertSettings\SubscribedAlertsWidget;
use App\Models\AlertSetting;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SubscribedAlertsWidgetTest extends TestCase
{
    #[Group('widgets')]
    #[Group('profile')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true)
        );

        $otherSmsAlerts = AlertSetting::factory()
            ->smsNotification([$user->mobile_phone])
            ->create([
                'org_id' => $user->org_id,
            ]);

        $otherEmailAlerts = AlertSetting::factory()
            ->emailNotification([$user->email])
            ->create([
                'org_id' => $user->org_id,
            ]);

        Livewire::test(SubscribedAlertsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Subscribed Alerts From Other Users')
            ->assertDontSeeText('No Other Alerts')
            ->assertSee('data-alert="'.$otherSmsAlerts->id.':SMS"', false)
            ->assertSee('data-alert="'.$otherEmailAlerts->id.':Email"', false);
    }

    #[Group('widgets')]
    #[Group('profile')]
    public function test_cannot_render_without_pro(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'])
        );

        $otherSmsAlerts = AlertSetting::factory()
            ->smsNotification([$user->mobile_phone])
            ->create([
                'org_id' => $user->org_id,
            ]);

        $otherEmailAlerts = AlertSetting::factory()
            ->emailNotification([$user->email])
            ->create([
                'org_id' => $user->org_id,
            ]);

        Livewire::test(SubscribedAlertsWidget::class)
            ->assertForbidden()
            ->assertDontSeeText('Subscribed Alerts From Other Users')
            ->assertDontSee('data-alert="'.$otherSmsAlerts->id.':SMS"', false)
            ->assertDontSee('data-alert="'.$otherEmailAlerts->id.':Email"', false);
    }

    #[Group('widgets')]
    #[Group('profile')]
    public function test_does_not_show_current_users_alerts(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true)
        );

        $myAlerts = AlertSetting::factory()
            ->forUser($user)
            ->emailNotification([$user->email])
            ->smsNotification([$user->mobile_phone])
            ->create();

        Livewire::test(SubscribedAlertsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Subscribed Alerts From Other Users')
            ->assertSeeText('No Other Alerts')
            ->assertDontSee('data-alert="'.$myAlerts->id.':', false);
    }

    #[Group('widgets')]
    #[Group('profile')]
    public function test_does_not_show_other_org_alerts_matching_my_phone(): void
    {
        $this->actingAs(
            $user = $this->createUser(['mobile_phone' => '(123) 456-7890'], pro: true)
        );

        $otherOrgAlerts = AlertSetting::factory()
            ->forUser(User::factory()->create())
            ->smsNotification([$user->mobile_phone])
            ->create();

        Livewire::test(SubscribedAlertsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('No Other Alerts')
            ->assertDontSee('data-alert="'.$otherOrgAlerts->id.':', false);
    }

    #[Group('widgets')]
    #[Group('profile')]
    public function test_can_render_empty(): void
    {
        $this->actingAs(
            $this->createUser(pro: true)
        );

        Livewire::test(SubscribedAlertsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Subscribed Alerts From Other Users')
            ->assertSeeText('No Other Alerts');
    }

    #[Group('widgets')]
    #[Group('profile')]
    public function test_does_not_show_other_org_users_alerts(): void
    {
        $this->actingAs(
            $user = $this->createUser(pro: true)
        );

        $otherOrgAlerts = AlertSetting::factory()
            ->forUser(User::factory()->create())
            ->emailNotification([$user->email])
            ->create();

        Livewire::test(SubscribedAlertsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Subscribed Alerts From Other Users')
            ->assertSeeText('No Other Alerts')
            ->assertDontSee('data-alert="'.$otherOrgAlerts->id.':Email"', false);
    }

    #[Group('widgets')]
    #[Group('profile')]
    public function test_renders_a_row_with_an_unknown_type_code(): void
    {
        $this->actingAs(
            $user = $this->createUser(pro: true)
        );

        $other = AlertSetting::factory()
            ->forUser(User::factory()->forOrg($user->org)->create())
            ->forTypes(types: ['ZZ'])
            ->emailNotification([$user->email])
            ->create();

        Livewire::test(SubscribedAlertsWidget::class)
            ->assertSuccessful()
            ->assertSee('data-alert="'.$other->id.':Email"', false);
    }
}
