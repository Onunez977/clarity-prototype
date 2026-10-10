<?php

namespace Tests\Filament\Pages;

use App\Enums\HotlistStatusEnum;
use App\Filament\Pages\KitUserRecoveries;
use App\Models\RecoveriesKitUserScansReport;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class KitUserRecoveriesTest extends TestCase
{
    use RequiresClickhouse;

    private function insertClarityRecord(RecoveriesKitUserScansReport $report): void
    {
        DB::connection('clarity_clickhouse')
            ->table('clarity.recovery_kit_scan_report')
            ->insert([
                'recovery_id' => $report->recovery_id,
                'kit_id' => $report->kit_id,
                'org_id' => $report->org_id,
                'kit_name' => $report->kit->name,
                'vin' => $report->vin,
                'plate' => $report->plate,
                'status' => $report->getRawOriginal('status'),
                'repo_date' => $report->repo_date,
                'kit_scan_count' => $report->count ?? 0,
                'total_scan_count' => $report->hotlist_scan_count ?? 0,
                'hotlist_scan_percentage' => $report->hotlist_scan_percentage ?? 0,
                '_peerdb_is_deleted' => 0,
            ]);
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_can_render_table(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $reports = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->count(3)
            ->create();

        $reports->each(fn ($report) => $this->insertClarityRecord($report));

        Livewire::test(KitUserRecoveries::class)
            ->assertSuccessful()
            ->assertSeeTextInOrder(
                $reports
                    ->map(fn ($report) => $report->kit->name)
                    ->sort()
                    ->toArray()
            );
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(KitUserRecoveries::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_can_render_table_columns(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $report = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create();

        $this->insertClarityRecord($report);

        Livewire::test(KitUserRecoveries::class)
            ->assertSuccessful()
            ->assertCanRenderTableColumn('kit_name')
            ->assertCanRenderTableColumn('vin')
            ->assertCanRenderTableColumn('plate')
            ->assertCanRenderTableColumn('hotlist_scan_percentage')
            ->assertCanRenderTableColumn('repo_date')
            ->assertCanRenderTableColumn('count')
            ->assertCanRenderTableColumn('hotlist_scan_count');
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_cannot_render_reports_from_other_org(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $reports = RecoveriesKitUserScansReport::factory()
            ->count(3)
            ->create();

        $reports->each(fn ($report) => $this->insertClarityRecord($report));

        $component = Livewire::test(KitUserRecoveries::class)
            ->assertSuccessful();

        $reports->each(fn ($report) => $component->assertDontSeeText($report->kit->name));
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_cannot_render_without_kit_id(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $reports = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->count(1)
            ->create([
                'kit_id' => null,
            ]);

        // Note: not inserting into ClickHouse since kit_id is null (filtered out by query)

        Livewire::test(KitUserRecoveries::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords($reports);
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_kit_id_filter(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $report = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create();

        $kitReport = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create();

        $this->insertClarityRecord($report);
        $this->insertClarityRecord($kitReport);

        Livewire::test(KitUserRecoveries::class)
            ->assertSuccessful()
            ->assertSeeText($report->kit->name)
            ->assertSeeText($kitReport->kit->name)
            ->set('kits.0', $kitReport->kit_id)
            ->call('search')
            ->assertSeeText($kitReport->kit->name)
            ->assertDontSeeText($report->kit->name);
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_date_filter(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $thisMonthReport = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create([
                'repo_date' => now(),
            ]);

        $lastMonthReport = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create([
                'repo_date' => now()->startOfMonth()->subMonth()->addDay(),
            ]);

        $this->insertClarityRecord($thisMonthReport);
        $this->insertClarityRecord($lastMonthReport);

        Livewire::test(KitUserRecoveries::class)
            ->assertSuccessful()
            ->set('start_date', now()->startOfMonth()->startOfDay())
            ->set('end_date', now()->endOfDay())
            ->call('search')
            ->assertSeeText($thisMonthReport->kit->name)
            ->assertDontSeeText($lastMonthReport->kit->name)
            ->set('start_date', now()->startOfMonth()->subMonth()->startOfDay())
            ->set('end_date', now()->startOfMonth()->subMonth()->endOfMonth()->endOfDay())
            ->call('search')
            ->assertDontSeeText($thisMonthReport->kit->name)
            ->assertSeeText($lastMonthReport->kit->name);
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_filter_search_dispatches_events(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $reports = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->count(2)
            ->create();

        $repoReport = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create([
                'status' => HotlistStatusEnum::Repossessed->value,
            ]);

        $reports->each(fn ($report) => $this->insertClarityRecord($report));
        $this->insertClarityRecord($repoReport);

        Livewire::test(KitUserRecoveries::class)
            ->assertSuccessful()
            ->call('search')
            ->assertDispatched('reload-recoveries-total')
            ->assertDispatched('reload-recoveries-leaderboard');
    }

    #[Group('resources')]
    #[Group('recoveries')]
    #[Group('kit-user-recoveries')]
    public function test_can_sort_by_hotlist_scan_percentage(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $sortReports = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->count(2)
            ->create();

        $sortReports->each(fn ($report) => $this->insertClarityRecord($report));

        Livewire::test(KitUserRecoveries::class)
            ->sortTable('hotlist_scan_percentage', 'asc')
            ->assertSuccessful();
    }
}
