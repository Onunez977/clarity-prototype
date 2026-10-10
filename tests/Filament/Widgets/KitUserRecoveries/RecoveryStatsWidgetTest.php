<?php

namespace Tests\Filament\Widgets\KitUserRecoveries;

use App\Filament\Widgets\KitUserRecoveries\RecoveryStatsWidget;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RecoveryStatsWidgetTest extends TestCase
{
    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        Livewire::test(RecoveryStatsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Leader')
            ->assertSeeText('Total Recoveries');
    }

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(RecoveryStatsWidget::class)
            ->assertForbidden()
            ->assertDontSeeText('Leader')
            ->assertDontSeeText('Total Recoveries');
    }

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_can_show_total(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $total = fake()->numberBetween(1, 1000);

        Livewire::test(RecoveryStatsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Total Recoveries')
            ->assertSeeText('0')
            ->assertDontSee('data-stat="Total Recoveries: '.$total.'"', false);
    }

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_can_reload_filters(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $filters = [
            'test' => 'here',
        ];

        Livewire::test(RecoveryStatsWidget::class)
            ->assertSuccessful()
            ->assertSet('pageFilters', null)
            // Dispatch event
            ->dispatch('reload-recoveries-total', $filters)
            ->assertSet('pageFilters', $filters);
    }

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_can_receive_leader_event(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $name = fake()->words(asText: true);

        Livewire::test(RecoveryStatsWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Leader')
            ->assertSeeText('N/A')
            ->assertDontSeeText($name)
            // Dispatch event
            ->dispatch('update-recovery-leader', $name)
            ->assertSee('data-stat="Leader: '.$name.'"', false);
    }
}
