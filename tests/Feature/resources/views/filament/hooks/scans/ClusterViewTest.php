<?php

namespace Tests\Feature\resources\views\filament\hooks\scans;

use App\Filament\Resources\ScansResource\Pages\ListScans;
use App\Models\Scan;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ClusterViewTest extends TestCase
{
    #[Group('pro')]
    #[Group('scans')]
    #[Group('cluster')]
    #[Group('scan-cluster')]
    public function test_can_see_cluster_view_as_pro(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value, pro: true)
        );

        $scan = Scan::factory()
            ->forOrg($user->org)
            ->create([
                'ocr' => '123456',
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->assertDontSeeText('No Scans')
            ->set('tableFilters.filters.ocr', $scan->ocr)
            ->assertSeeText($scan->ocr)
            ->assertSee('<section class="cluster-view">', false)
            ->assertSee('data-cluster-record="'.$scan->id.'"', false);
    }

    #[Group('pro')]
    #[Group('scans')]
    #[Group('cluster')]
    #[Group('scan-cluster')]
    public function test_cannot_see_cluster_view_without_pro(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value)
        );

        $scan = Scan::factory()
            ->forOrg($user->org)
            ->create([
                'ocr' => '123456',
            ]);

        Livewire::test(ListScans::class)
            ->assertSuccessful()
            ->assertDontSeeText('No Scans')
            ->set('tableFilters.filters.ocr', $scan->ocr)
            ->assertSeeText($scan->ocr)
            // Not pro
            ->assertDontSee('<section class="cluster-view">', false)
            ->assertDontSee('data-cluster-record="'.$scan->id.'"', false);
    }
}
