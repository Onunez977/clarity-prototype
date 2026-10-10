<?php

namespace Tests\Filament\Actions\MissedOpportunities;

use App\Filament\Resources\MissedOpportunityResource\Actions\EmailMissedOpportunitiesAction;
use App\Mail\MissedOpportunities\NewLienholderMissedOpportunity;
use App\Models\MissedOpportunity;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class EmailMissedOpportunitiesActionTest extends TestCase
{
    #[Group('actions')]
    public function test_can_render(): void
    {
        $this->actingAs(
            $this->createUser()
        );

        $action = EmailMissedOpportunitiesAction::make();

        $this->assertEquals('Email Missed Opportunities', $action->getLabel());
        $this->assertEquals('email-missed-opportunities', $action->getName());
    }

    #[Group('actions')]
    public function test_can_queue_emails_with_custom_recipients(): void
    {
        Mail::fake();

        $this->actingAs(
            $this->createUser()
        );

        $missedOpportunities = MissedOpportunity::factory()
            ->count(2)
            ->create();

        $action = EmailMissedOpportunitiesAction::make()
            ->table(
                (new class extends ListRecords
                {
                    public function __construct()
                    {
                        // Force create dummy table.
                        $this->table = Table::make($this)
                            ->query(MissedOpportunity::query());
                    }
                })->getTable(),
            );

        $testEmails = ['test1@example.com', 'test2@example.com'];

        $this->evaluate($action->getActionFunction(), [
            'records' => $missedOpportunities,
            'data' => [
                'email_recipients' => $testEmails,
            ],
        ]);

        Mail::assertQueued(NewLienholderMissedOpportunity::class);

        // Single email with all records to custom recipients.
        Mail::assertQueuedCount(1);
    }
}
