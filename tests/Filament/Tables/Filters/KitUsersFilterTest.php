<?php

namespace Tests\Filament\Tables\Filters;

use App\Filament\Tables\Filters\KitUsersFilter;
use App\Models\Kit;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\HasSuperUser;
use Tests\TestCase;

class KitUsersFilterTest extends TestCase
{
    use HasSuperUser;

    #[Group('tables')]
    #[Group('filters')]
    public function test_can_format_all_kits(): void
    {
        $orgKit = Kit::factory()
            ->forOrg($this->user->org)
            ->create();

        $formatted = KitUsersFilter::format(['All Kits']);

        $this->assertContains($orgKit->id, $formatted);
        $this->assertNotContains('All Kits', $formatted);
    }

    #[Group('tables')]
    #[Group('filters')]
    public function test_can_indicate_all_kits(): void
    {
        $this->assertEquals('All Kit Users', KitUsersFilter::indicator(['All Kits']));
    }

    #[Group('tables')]
    #[Group('filters')]
    public function test_cannot_format_kits_from_other_org(): void
    {
        $orgKit = Kit::factory()
            ->forOrg($this->user->org)
            ->create();

        $otherKit = Kit::factory()
            ->create();

        $formatted = KitUsersFilter::format([$orgKit->id, $otherKit->kit_id]);

        $this->assertContains($orgKit->id, $formatted);
        $this->assertNotContains($otherKit->id, $formatted);
    }

    #[Group('tables')]
    #[Group('filters')]
    public function test_can_indicate(): void
    {
        $this->assertEquals('Kit Users (3)', KitUsersFilter::indicator(['1', '2', '3']));

        $this->assertEquals('Kit Users (10+)', KitUsersFilter::indicator(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11']));
    }
}
