<?php

namespace Tests\Filament\Tables\Columns;

use App\Filament\Tables\Columns\DateTimeColumn;
use App\Models\Scan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\HasSuperUser;
use Tests\TestCase;

class ScannedColumnTest extends TestCase
{
    use HasSuperUser;

    #[Group('tables')]
    #[Group('columns')]
    public function test_can_render_day_scan(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'scanned_at' => now()->midDay(),
            ]);

        $column = (new DateTimeColumn(name: 'scanned_at'))
            ->record($scan)
            ->showIcon();

        $this->assertStringContainsString($scan->scanned_at->diffForHumans(), $column->getDescriptionBelow());
        $this->assertEquals('Day Scan', $column->getTooltip());
    }

    #[Group('tables')]
    #[Group('columns')]
    public function test_can_render_night_scan(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'scanned_at' => now()->endOfDay(),
            ]);

        $column = (new DateTimeColumn(name: 'scanned_at'))
            ->record($scan)
            ->showIcon();

        $this->assertStringContainsString($scan->scanned_at->diffForHumans(), $column->getDescriptionBelow());
        $this->assertEquals('Night Scan', $column->getTooltip());
    }

    #[Group('tables')]
    #[Group('columns')]
    public function test_cannot_render_icon_and_tooltip(): void
    {
        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'scanned_at' => now()->midDay(),
            ]);

        $column = (new DateTimeColumn(name: 'scanned_at'))
            ->record($scan)
            ->showIcon(show: false);

        $this->assertStringContainsString($scan->scanned_at->diffForHumans(), $column->getDescriptionBelow());
        $this->assertNotEquals('Day Scan', $column->getTooltip());
    }
}
