<?php

namespace App\Filament\Resources\MissedOpportunityResource\Actions;

use App\Mail\MissedOpportunities\NewLienholderMissedOpportunity;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Filament\Notifications;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class EmailMissedOpportunitiesAction extends BulkAction
{
    protected ?string $name = 'email-missed-opportunities';

    protected string|Htmlable|Closure|null $label = 'Email Missed Opportunities';

    protected \BackedEnum|Htmlable|Closure|string|false|null $icon = 'heroicon-m-paper-airplane';

    protected string|Closure|Htmlable|null $tooltip = 'Send an Email with the Missed Opportunity details to specified recipients.';

    protected bool|Closure $shouldDeselectRecordsAfterCompletion = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extraAttributes(trackAttr('missed-opportunity', 'email-button'));

        $this->modalHeading('Email Missed Opportunities');

        $this->form([
            Forms\Components\TagsInput::make('email_recipients')
                ->label('Email Recipients')
                ->placeholder('Enter email addresses and press Enter')
                ->required()
                ->validationMessages([
                    'required' => 'At least one email address is required.',
                ])
                ->helperText('Enter one or more email addresses to send the missed opportunity details to.')
                ->rules([
                    'array',
                    function ($attribute, $value, $fail) {
                        if (is_array($value)) {
                            foreach ($value as $email) {
                                if (! Validator::make(['email' => $email], ['email' => 'email'])->passes()) {
                                    $fail('The email address \''.$email.'\' is not valid.');
                                    break;
                                }
                            }
                        }
                    },
                ]),

        ]);

        $this->modalSubmitActionLabel('Send Email(s)');

        $this->action = function ($records, $data) {
            $emailRecipients = $data['email_recipients'];

            // Send all records in a single email to the custom recipients
            $recordsArray = $records
                ->each->unsetRelation('hotlist')
                ->toArray();

            Mail::to($emailRecipients)
                ->queue(new NewLienholderMissedOpportunity(
                    records: $recordsArray,
                    sender: auth()->user()->getFilamentName(),
                    timezone: auth()->user()->timezone ?? 'UTC'
                ));

            $recipientCount = count($emailRecipients);
            $recordCount = $records->count();

            Notifications\Notification::make()
                ->title('Email(s) Sent Successfully')
                ->body('Sent '.$recordCount.' missed opportunity details to '.$recipientCount.' recipient(s).')
                ->success()
                ->send();
        };
    }

    public static function getDefaultName(): ?string
    {
        return 'email-missed-opportunities';
    }
}
