<?php

namespace Tests\Filament\Actions;

use App\Filament\Actions\BulkMapAction;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class BulkMapActionTest extends TestCase
{
    #[Group('actions')]
    public function test_can_render(): void
    {
        $action = BulkMapAction::make('map');

        $this->assertNull($action->getActionFunction());
        $this->assertNull($action->relationship);

        $this->assertNotNull($action->getAlpineClickHandler());
        $this->assertEquals('$dispatch("load-records-for-map", { recordIds: entangledSelectedRecords })', $action->getAlpineClickHandler());
    }

    #[Group('actions')]
    public function test_can_set_relationship(): void
    {
        $action = BulkMapAction::make('map')
            ->setRelationship('scans');

        $this->assertNotNull($action->getAlpineClickHandler());
        $this->assertNull($action->getActionFunction());
        $this->assertEquals('scans', $action->relationship);
    }
}
