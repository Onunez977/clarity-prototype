<?php

namespace Tests\Filament\Resources;

use App\Filament\Client\Resources\ClientSearchScansResource\Pages\ListClientSearchScans;
use App\Models\Org;
use App\Models\Scan;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\ResourceTestCase;

class ClientSearchScansResourceTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::CLIENT->value;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(
            $this->user = $this->createClientPortalUser()
        );
    }

    #[Group('resources')]
    #[Group('scans')]
    #[Group('client-scans')]
    public function test_can_render_table(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->accessibleRepoOrg())
            ->create([
                'scanned_at' => now(tz: $this->user->timezone)->subHour(),
            ]);

        Livewire::test(ListClientSearchScans::class)
            ->assertSuccessful()
            // Initially empty with no filters.
            ->assertCanNotSeeTableRecords([$scan])
            ->assertSeeText('Search for a plate number under the filters section.')
            ->set('tableFilters.filters.plate', $scan->ocr)
            ->assertCanSeeTableRecords([$scan]);
    }

    #[Group('resources')]
    #[Group('scans')]
    #[Group('client-scans')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(role: [])
        );

        Livewire::test(ListClientSearchScans::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('scans')]
    #[Group('client-scans')]
    public function test_can_render_table_columns(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->accessibleRepoOrg())
            ->create([
                'scanned_at' => now(tz: $this->user->timezone)->subHour(),
            ]);

        Livewire::test(ListClientSearchScans::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.plate', $scan->ocr)
            ->assertCanSeeTableRecords([$scan])
            ->assertCanRenderTableColumn('ocr')
            ->assertCanRenderTableColumn('scanned_at');
    }

    #[Group('resources')]
    #[Group('scans')]
    #[Group('client-scans')]
    public function test_date_filters(): void
    {
        $scanThisMonth = Scan::factory()
            ->forOrg($this->accessibleRepoOrg())
            ->create([
                'ocr' => '123456',
                'scanned_at' => now(tz: $this->user->timezone),
            ]);

        $scanLastMonth = Scan::factory()
            ->forOrg($this->accessibleRepoOrg())
            ->create([
                'ocr' => '123456',
                'scanned_at' => now(tz: $this->user->timezone)->subMonth(),
            ]);

        Livewire::test(ListClientSearchScans::class)
            ->assertSuccessful()
            ->set('tableFilters.filters.plate', '123456')
            ->set('tableFilters.scanned_at.start', now(tz: $this->user->timezone)->startOfDay())
            ->set('tableFilters.scanned_at.end', now(tz: $this->user->timezone)->endOfDay())
            ->assertCanSeeTableRecords([$scanThisMonth])
            ->assertCanNotSeeTableRecords([$scanLastMonth])
            ->set('tableFilters.scanned_at.start', now(tz: $this->user->timezone)->subMonth()->startOfDay())
            ->set('tableFilters.scanned_at.end', now(tz: $this->user->timezone)->subMonth()->endOfDay())
            ->assertCanNotSeeTableRecords([$scanThisMonth])
            ->assertCanSeeTableRecords([$scanLastMonth]);
    }

    private function accessibleRepoOrg(): Org
    {
        return Org::findOrFail($this->user->accessible_repo_org_ids[0]);
    }
}
