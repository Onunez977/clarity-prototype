<?php

declare(strict_types=1);

namespace Tests\Filament\Widgets\Client;

use App\Filament\Widgets\Client\AssignmentBreakdownChart;
use App\Filament\Widgets\Client\ClientTable;
use App\Filament\Widgets\Client\Lifetime\LifetimeLprAssistsWidget;
use App\Filament\Widgets\Client\TotalAssignmentsWidget;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ClientTableBranchFilterTest extends TestCase
{
    #[Group('client-analytics')]
    #[Group('widgets')]
    public function test_refresh_data_clears_branch_id_when_all_branches_is_selected(): void
    {
        $table = new ClientTable;
        $table->branch_id = 17;
        $table->hiddenClientsCount = 4;

        $table->refreshData(null);

        $this->assertNull($table->branch_id);
        $this->assertNull($table->hiddenClientsCount);
    }

    #[Group('client-analytics')]
    #[Group('widgets')]
    public function test_refresh_data_sets_integer_branch_id_from_string_payload(): void
    {
        $table = new ClientTable;

        $table->refreshData('9');

        $this->assertSame(9, $table->branch_id);
    }

    #[Group('client-analytics')]
    #[Group('widgets')]
    public function test_refresh_dashboard_event_clears_branch_id(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        Livewire::test(ClientTable::class)
            ->set('org_id', auth()->user()->org_id)
            ->set('branch_id', 17)
            ->set('hiddenClientsCount', 0)
            ->dispatch('refresh-dashboard', branch_id: null)
            ->assertSet('branch_id', null);
    }

    #[Group('client-analytics')]
    #[Group('widgets')]
    public function test_stat_widget_refresh_data_clears_branch_and_client_filters(): void
    {
        $widget = new TotalAssignmentsWidget;
        $widget->branch_id = 17;
        $widget->client_org_id = 99;

        $widget->refreshData(null, client_org_id: null);

        $this->assertNull($widget->branch_id);
        $this->assertNull($widget->client_org_id);
    }

    #[Group('client-analytics')]
    #[Group('widgets')]
    public function test_clickhouse_chart_and_lifetime_concerns_clear_branch_and_client_filters(): void
    {
        $chart = new AssignmentBreakdownChart;
        $chart->branch_id = 3;
        $chart->client_org_id = 8;
        $chart->refreshData(null, client_org_id: null);
        $this->assertNull($chart->branch_id);
        $this->assertNull($chart->client_org_id);

        $lifetime = new LifetimeLprAssistsWidget;
        $lifetime->branch_id = 3;
        $lifetime->client_org_id = 8;
        $lifetime->refreshData(null, client_org_id: null);
        $this->assertNull($lifetime->branch_id);
        $this->assertNull($lifetime->client_org_id);
    }

    #[Group('client-analytics')]
    #[Group('widgets')]
    public function test_non_numeric_branch_payload_clears_instead_of_coercing_to_zero(): void
    {
        $widget = new TotalAssignmentsWidget;
        $widget->branch_id = 17;

        $widget->refreshData('not-a-branch');

        $this->assertNull($widget->branch_id);
    }
}
