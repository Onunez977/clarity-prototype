<?php

namespace Tests\Filament\Resources;

use App\Filament\Resources\ScanReportResource\Pages\ListScanReports;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\ResourceTestCase;

class ScanReportResourceTest extends ResourceTestCase
{
    protected mixed $userPermissions = PermissionEnum::Reports_View_Scan_Report->value;

    #[Group('resources')]
    #[Group('scan-report')]
    #[Group('scan-reports')]
    public function test_can_render_table(): void
    {
        Livewire::test(ListScanReports::class)
            ->assertSuccessful();
    }

    #[Group('resources')]
    #[Group('scan-report')]
    #[Group('scan-reports')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(ListScanReports::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('scan-report')]
    #[Group('scan-reports')]
    public function test_can_render_table_columns(): void
    {
        Livewire::test(ListScanReports::class)
            ->assertSuccessful()
            ->assertCanRenderTableColumn('name')
            ->assertTableColumnVisible('name')
            ->assertCanRenderTableColumn('one_hour')
            ->assertTableColumnVisible('one_hour')
            ->assertCanRenderTableColumn('four_hour')
            ->assertTableColumnVisible('four_hour')
            ->assertCanRenderTableColumn('one_day')
            ->assertTableColumnVisible('one_day')
            ->assertCanRenderTableColumn('seven_day')
            ->assertTableColumnVisible('seven_day')
            ->assertCanRenderTableColumn('thirty_day')
            ->assertTableColumnVisible('thirty_day')
            ->assertCanRenderTableColumn('one_year')
            ->assertTableColumnVisible('one_year');
    }
}
