<?php

namespace Tests\Roles;

use App\Filament\Client\Resources\ClientSearchScansResource;
use App\Filament\Client\Resources\ClientSearchScansResource\Pages\ListClientSearchScans;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\KitUserPath;
use App\Filament\Pages\KitUserRecoveries;
use App\Filament\Pages\Roles;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\AffiliateStatsResource;
use App\Filament\Resources\HitsResource;
use App\Filament\Resources\HotlistResource;
use App\Filament\Resources\ScansResource;
use App\Filament\Resources\UsersResource;
use App\Models\Org;
use App\Models\Scan;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\RoleTestCase;

class ClientRoleTest extends RoleTestCase
{
    protected RoleEnum $role = RoleEnum::CLIENT;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(
            $this->user = $this->createClientPortalUser(role: RoleEnum::CLIENT->value)
        );
    }

    #[Group('client')]
    public function test_can_only_see_client_search_scans(): void
    {
        Livewire::test(ListClientSearchScans::class)->assertSuccessful();

        $this->get(ClientSearchScansResource::getUrl(panel: 'client'))->assertSuccessful();

        $this->get(Dashboard::getUrl(panel: 'app'))->assertForbidden();

        collect([
            ScansResource::getUrl(panel: 'app'),
            HitsResource::getUrl(panel: 'app'),
            HotlistResource::getUrl(panel: 'app'),
            KitUserPath::getUrl(panel: 'app'),
            AffiliateStatsResource::getUrl(panel: 'app'),
            KitUserRecoveries::getUrl(panel: 'app'),
            UsersResource::getUrl(panel: 'app'),
            Roles::getUrl(panel: 'app'),
            ActivityResource::getUrl(panel: 'app'),
        ])->each(function ($resource) {
            $this->get($resource)->assertForbidden();
        });
    }

    #[Group('client')]
    public function test_can_search_client_scans(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->accessibleRepoOrg())
            ->create([
                'scanned_at' => now(tz: $this->user->timezone)->subHour(),
            ]);

        Livewire::test(ListClientSearchScans::class)
            ->assertSuccessful()
            ->assertSet('tableFilters.filters.plate', '')
            ->assertSeeText('No scans')
            ->set('tableFilters.filters.plate', $scan->ocr)
            ->assertDontSeeText('No scans')
            ->assertCanRenderTableColumn('ocr')
            ->assertSeeText($scan->ocr)
            ->assertSeeText($scan->us_state)
            ->assertCanRenderTableColumn('scanned_at')
            ->assertSee('alt="Plate image for '.$scan->ocr.'"', false)
            ->assertDontSee('alt="Car image for '.$scan->ocr.'"', false);
    }

    #[Group('client')]
    public function test_cannot_search_client_scans_from_other_org(): void
    {
        $scan = Scan::factory()->create([
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListClientSearchScans::class)
            ->assertSuccessful()
            ->assertSet('tableFilters.filters.plate', '')
            ->assertSeeText('No scans')
            ->set('tableFilters.filters.plate', $scan->ocr)
            ->assertSeeText('No scans')
            ->assertCanNotRenderTableColumn('ocr')
            ->assertCanNotRenderTableColumn('scanned_at')
            ->assertDontSeeText($scan->scanned_at->format(config('app.date_format')))
            ->assertDontSee('alt="Plate image for '.$scan->ocr.'"', false)
            ->assertDontSee('alt="Car image for '.$scan->ocr.'"', false);
    }

    private function accessibleRepoOrg(): Org
    {
        return Org::findOrFail($this->user->accessible_repo_org_ids[0]);
    }
}
