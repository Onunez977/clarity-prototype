<?php

declare(strict_types=1);

namespace App\Mail\MissedOpportunities;

use App\Models\MissedOpportunity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class NewNotification extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        public MissedOpportunity $missedOpportunity,
        public int $alertSettingId,
        public string $alert_email,
        public string $timezone = 'UTC',
    ) {
        $this->onQueue('{high}');

        $this->missedOpportunity->loadMissing('scan', 'hotlist');

        $this->subject(sprintf(
            'New Missed Opportunity from Clarity: %s - %s %s',
            $this->missedOpportunity->hotlist?->integration?->name ?? 'Unknown Hotlist',
            $this->missedOpportunity->hotlist?->plate ?? 'Unknown Plate',
            $this->missedOpportunity->hotlist?->us_state ?? 'Unknown State'
        ));
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        $alertUnsubscribeUrl = URL::temporarySignedRoute(
            'email.unsubscribe.alert',
            now()->addDays(30),
            [
                'alert_setting_id' => $this->alertSettingId,
                'email' => $this->alert_email,
            ]
        );

        $allUnsubscribeUrl = URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->addDays(30),
            [
                'email' => $this->alert_email,
            ]
        );

        return $this->markdown('mail.missed-opportunities.new-notification')
            ->with([
                'alert_unsubscribe_url' => $alertUnsubscribeUrl,
                'all_unsubscribe_url' => $allUnsubscribeUrl,
            ]);
    }
}
