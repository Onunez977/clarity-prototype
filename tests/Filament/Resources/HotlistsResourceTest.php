<?php

namespace Tests\Filament\Resources;

use App\Actions\Activity\Log\HotlistsSearch;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Enums\HotlistStatusEnum;
use App\Filament\Resources\HotlistResource\Pages\ListHotlists;
use App\Jobs\LogFilterActivity;
use App\Models\Org;
use Illuminate\Support\Facades\Queue;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\RequiresClickhouse;
use Tests\ResourceTestCase;

class HotlistsResourceTest extends ResourceTestCase
{
    use RequiresClickhouse;

    protected mixed $userPermissions = PermissionEnum::ViewAny_Hotlist->value;

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_can_render_table(): void
    {
        $hotlists = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->count(3)
            ->create();

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            ->assertCanSeeTableRecords($hotlists);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(ListHotlists::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_can_render_table_columns(): void
    {
        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create();

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            ->assertCanRenderTableColumn('plate')
            ->assertCanRenderTableColumn('status')
            ->assertCanRenderTableColumn('vin')
            ->assertCanRenderTableColumn('vehicle_make')
            ->assertTableColumnFormattedStateSet('vehicle_make', \trim($hotlist->vehicle_year.' '.$hotlist->vehicle_make.' '.$hotlist->vehicle_model), record: $hotlist)
            ->assertCanRenderTableColumn('remote_order_id')
            ->assertCanRenderTableColumn('created_at')
            ->assertCanRenderTableColumn('client_org_id')
            ->assertCanRenderTableColumn('lienholder_org_id');
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_status_filter(): void
    {
        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'status' => HotlistStatusEnum::Open->value,
            ]);

        $otherHotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'status' => HotlistStatusEnum::Other->value,
            ]);

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            // Default status filter is set to "Open"
            ->assertCanSeeTableRecords([$hotlist])
            ->assertCanNotSeeTableRecords([$otherHotlist])
            ->set('tableFilters.filters.status', HotlistStatusEnum::Other->value)
            ->assertCanNotSeeTableRecords([$hotlist])
            ->assertCanSeeTableRecords([$otherHotlist]);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_vin_filter(): void
    {
        Queue::fake();

        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'vin' => '123456',
            ]);

        $otherHotlists = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            ->assertCanSeeTableRecords($otherHotlists->merge([$hotlist]))
            ->set('tableFilters.filters.vin', $hotlist->vin)
            ->assertCanSeeTableRecords([$hotlist])
            ->assertCanNotSeeTableRecords($otherHotlists);

        Queue::assertPushed(LogFilterActivity::class);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_plate_filter(): void
    {
        Queue::fake();

        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'plate' => '123456',
            ]);

        $otherHotlists = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            ->assertCanSeeTableRecords($otherHotlists->merge([$hotlist]))
            ->set('tableFilters.filters.plate', $hotlist->plate)
            ->assertCanSeeTableRecords([$hotlist])
            ->assertCanNotSeeTableRecords($otherHotlists);

        Queue::assertPushed(LogFilterActivity::class);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_order_id_filter(): void
    {
        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'remote_order_id' => 123456789,
            ]);

        $otherHotlists = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            ->assertCanSeeTableRecords($otherHotlists->merge([$hotlist]))
            ->set('tableFilters.filters.order_id', $hotlist->remote_order_id)
            ->assertCanSeeTableRecords([$hotlist])
            ->assertCanNotSeeTableRecords($otherHotlists);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_client_filter(): void
    {
        $client = Org::factory()->create();

        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'client_org_id' => $client->id,
            ]);

        $otherHotlists = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            ->assertCanSeeTableRecords($otherHotlists->merge([$hotlist]))
            ->set('tableFilters.filters.clients.0', $client->id)
            ->assertCanSeeTableRecords([$hotlist])
            ->assertCanNotSeeTableRecords($otherHotlists);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_missing_plate_filter(): void
    {
        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'plate' => '',
            ]);

        $otherHotlists = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->loadTable()
            ->assertCanSeeTableRecords($otherHotlists->merge([$hotlist]))
            ->set('tableFilters.filters.missing_plate', true)
            ->assertCanSeeTableRecords([$hotlist])
            ->assertCanNotSeeTableRecords($otherHotlists);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_logs_vin_filter_activity(): void
    {
        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'vin' => '123456',
            ]);

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.vin', $hotlist->vin)
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => HotlistsSearch::$log_name,
            'description' => $this->user->email.' searched for hotlists by VIN',
        ]);

        $activity = Activity::query()
            ->where('log_name', HotlistsSearch::$log_name)
            ->where('description', $this->user->email.' searched for hotlists by VIN')
            ->first();

        $this->assertArrayHasKey('vin', $activity->properties);
        $this->assertEquals([$hotlist->vin], $activity->properties['vin']);
    }

    #[Group('resources')]
    #[Group('hotlist')]
    #[Group('hotlists')]
    public function test_logs_plate_filter_activity(): void
    {
        $hotlist = ClickhouseHotlist::factory()
            ->forOrg($this->user->org)
            ->create([
                'plate' => '123456',
            ]);

        // Clear any existing activity.
        Activity::truncate();
        $this->assertDatabaseCount('activity_log', 0);

        Livewire::test(ListHotlists::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.plate', $hotlist->plate)
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => HotlistsSearch::$log_name,
            'description' => $this->user->email.' searched for hotlists by Plate',
        ]);

        $activity = Activity::query()
            ->where('log_name', HotlistsSearch::$log_name)
            ->where('description', $this->user->email.' searched for hotlists by Plate')
            ->first();

        $this->assertArrayHasKey('plateNumber', $activity->properties);
        $this->assertEquals([$hotlist->plate], $activity->properties['plateNumber']);
    }
}
