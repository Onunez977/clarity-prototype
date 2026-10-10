<?php

namespace Tests\Filament\Actions;

use App\Enums\DispositionEnum;
use App\Filament\Actions\BulkDispositionAction;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class BulkDispositionActionTest extends TestCase
{
    #[Group('actions')]
    public function test_can_render(): void
    {
        $action = BulkDispositionAction::make('bulk-exitdisposition');

        $this->assertEquals('Bulk Disposition', $action->getLabel());
        $this->assertEquals('heroicon-m-viewfinder-circle', $action->getIcon());
        $this->assertTrue($action->shouldDeselectRecordsAfterCompletion());
        $this->assertFalse($action->missedOpportunity);
        $this->assertNull($action->relationship);
    }

    #[Group('actions')]
    public function test_can_set_missed_opportunity(): void
    {
        $action = BulkDispositionAction::make('bulk_disposition')
            ->setMissedOpportunity(true);

        $this->assertTrue($action->missedOpportunity);
    }

    #[Group('actions')]
    public function test_can_set_relationship(): void
    {
        $action = BulkDispositionAction::make('bulk_disposition')
            ->setRelationship('scans');

        $this->assertEquals('scans', $action->relationship);
    }

    #[Group('actions')]
    public function test_disposition_enum_form_options_normal(): void
    {
        $options = DispositionEnum::formOptions(missedOpportunity: false);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('New Hit', $options);
        $this->assertArrayHasKey('Accessible', $options);
        $this->assertArrayHasKey('Moving', $options);
        $this->assertArrayHasKey('Inaccessible', $options);
        $this->assertArrayHasKey('Invalid Scan', $options);

        // Should not have missed opportunity options
        $this->assertArrayNotHasKey('Valid', $options);
        $this->assertArrayNotHasKey('Invalid', $options);
        $this->assertArrayNotHasKey('Reviewed', $options);
    }

    #[Group('actions')]
    public function test_disposition_enum_form_options_missed_opportunity(): void
    {
        $options = DispositionEnum::formOptions(missedOpportunity: true);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('Valid', $options);
        $this->assertArrayHasKey('Invalid', $options);
        $this->assertArrayHasKey('Reviewed', $options);

        // Should not have normal options
        $this->assertArrayNotHasKey('New Hit', $options);
        $this->assertArrayNotHasKey('Accessible', $options);
        $this->assertArrayNotHasKey('Moving', $options);
        $this->assertArrayNotHasKey('Inaccessible', $options);
        $this->assertArrayNotHasKey('Invalid Scan', $options);
    }
}
