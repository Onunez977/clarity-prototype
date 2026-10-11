<?php

namespace Tests\Filament\Resources;

use App\Filament\Resources\AffiliateStatsResource\Pages\ListAffiliateStats;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\ResourceTestCase;

class AffiliateStatsResourceTest extends ResourceTestCase
{
    protected mixed $userPermissions = PermissionEnum::Reports_View_Affiliate_Stats->value;

    #[Group('resources')]
    #[Group('affiliate')]
    #[Group('affiliate-stats')]
    public function test_can_render_table(): void
    {
        Livewire::test(ListAffiliateStats::class)
            ->assertSuccessful();
    }

    #[Group('resources')]
    #[Group('affiliate')]
    #[Group('affiliate-stats')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(ListAffiliateStats::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('affiliate')]
    #[Group('affiliate-stats')]
    public function test_can_render_table_columns(): void
    {
        Livewire::test(ListAffiliateStats::class)
            ->assertSuccessful()
            ->assertCanRenderTableColumn('name')
            ->assertTableColumnVisible('name')
            ->assertCanRenderTableColumn('scan_total')
            ->assertTableColumnVisible('scan_total')
            ->assertCanRenderTableColumn('unique_scans')
            ->assertTableColumnVisible('unique_scans')
            ->assertCanRenderTableColumn('unique_ratio')
            ->assertTableColumnVisible('unique_ratio')
            ->assertCanRenderTableColumn('total_days')
            ->assertTableColumnVisible('total_days')
            ->assertCanRenderTableColumn('average_per_day')
            ->assertTableColumnVisible('average_per_day')
            ->assertCanRenderTableColumn('total_hours')
            ->assertTableColumnVisible('total_hours')
            ->assertCanRenderTableColumn('average_per_hour')
            ->assertTableColumnVisible('average_per_hour');
    }
}
