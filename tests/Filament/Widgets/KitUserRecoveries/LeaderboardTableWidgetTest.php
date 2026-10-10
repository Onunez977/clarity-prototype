<?php

namespace Tests\Filament\Widgets\KitUserRecoveries;

use App\Filament\Widgets\KitUserRecoveries\LeaderboardTableWidget;
use App\Models\Kit;
use App\Models\RecoveriesKitUserScansReport;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

class LeaderboardTableWidgetTest extends TestCase
{
    use RequiresClickhouse;

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_can_render_empty(): void
    {
        $this->actingAs(
            $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        Livewire::test(LeaderboardTableWidget::class)
            ->assertSuccessful()
            ->assertSeeText('No Leaderboard Entries');
    }

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_cannot_render_without_permission(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(LeaderboardTableWidget::class)
            ->assertForbidden()
            ->assertDontSeeText('No Leaderboard Entries');
    }

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $kit = Kit::factory()->create([
            'org_id' => $user->org_id,
        ]);

        $recoveries = RecoveriesKitUserScansReport::factory()
            ->count(2)
            ->create([
                'kit_id' => $kit->id,
                'org_id' => $kit->org_id,
                'hotlist_scan_percentage' => 50.0,
            ]);

        $recoveries->each(fn ($r) => DB::connection('clarity_clickhouse')
            ->table('recovery_kit_scan_report')
            ->insert([
                'recovery_id' => $r->recovery_id,
                'kit_id' => $r->kit_id,
                'org_id' => $r->org_id,
                'kit_name' => $r->kit->name,
                'vin' => $r->vin,
                'plate' => $r->plate,
                'status' => $r->status instanceof \BackedEnum ? $r->status->value : $r->status,
                'repo_date' => $r->repo_date,
                'kit_scan_count' => $r->hotlist_scan_count ?? 0,
                'total_scan_count' => $r->count ?? 0,
                'hotlist_scan_percentage' => $r->hotlist_scan_percentage ?? 0,
            ]));

        $component = Livewire::test(LeaderboardTableWidget::class)
            ->assertSuccessful()
            ->assertDontSeeText('No Leaderboard Entries');

        $recoveries
            ->map(fn ($recovery) => $recovery->kit->name)
            ->each(fn ($kitName) => $component->assertSeeText($kitName));
    }

    #[Group('widgets')]
    #[Group('recoveries')]
    public function test_date_filters(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value)
        );

        $thisMonthReport = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create([
                'repo_date' => now(),
                'hotlist_scan_percentage' => 50.0,
            ]);
        DB::connection('clarity_clickhouse')->table('recovery_kit_scan_report')->insert([
            'recovery_id' => $thisMonthReport->recovery_id,
            'kit_id' => $thisMonthReport->kit_id,
            'org_id' => $thisMonthReport->org_id,
            'kit_name' => $thisMonthReport->kit->name,
            'vin' => $thisMonthReport->vin,
            'plate' => $thisMonthReport->plate,
            'status' => $thisMonthReport->status instanceof \BackedEnum ? $thisMonthReport->status->value : $thisMonthReport->status,
            'repo_date' => $thisMonthReport->repo_date,
            'kit_scan_count' => $thisMonthReport->hotlist_scan_count ?? 0,
            'total_scan_count' => $thisMonthReport->count ?? 0,
            'hotlist_scan_percentage' => $thisMonthReport->hotlist_scan_percentage ?? 0,
        ]);

        $lastMonthReport = RecoveriesKitUserScansReport::factory()
            ->forOrg($user->org)
            ->create([
                'repo_date' => now()->subMonth(),
                'hotlist_scan_percentage' => 50.0,
            ]);
        DB::connection('clarity_clickhouse')->table('recovery_kit_scan_report')->insert([
            'recovery_id' => $lastMonthReport->recovery_id,
            'kit_id' => $lastMonthReport->kit_id,
            'org_id' => $lastMonthReport->org_id,
            'kit_name' => $lastMonthReport->kit->name,
            'vin' => $lastMonthReport->vin,
            'plate' => $lastMonthReport->plate,
            'status' => $lastMonthReport->status instanceof \BackedEnum ? $lastMonthReport->status->value : $lastMonthReport->status,
            'repo_date' => $lastMonthReport->repo_date,
            'kit_scan_count' => $lastMonthReport->hotlist_scan_count ?? 0,
            'total_scan_count' => $lastMonthReport->count ?? 0,
            'hotlist_scan_percentage' => $lastMonthReport->hotlist_scan_percentage ?? 0,
        ]);

        Livewire::test(LeaderboardTableWidget::class)
            ->assertSuccessful()
            ->call('reload', [
                'kits' => [],
                'start_date' => now()->startOfDay(),
                'end_date' => now()->endOfDay(),
            ])
            ->assertDontSeeText($lastMonthReport->kit->name)
            ->assertSeeText($thisMonthReport->kit->name)
            ->call('reload', [
                'kits' => [],
                'start_date' => now()->subMonth()->startOfDay(),
                'end_date' => now()->subMonth()->endOfDay(),
            ])
            ->assertSeeText($lastMonthReport->kit->name)
            ->assertDontSeeText($thisMonthReport->kit->name);
    }
}
