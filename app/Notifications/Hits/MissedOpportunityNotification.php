<?php

namespace App\Notifications\Hits;

use App\Enums\FeatureEnum;
use App\Filament\Resources\MissedOpportunityResource\Pages\ListMissedOpportunities;
use App\Mail;
use App\Models\AlertSetting;
use App\Models\MissedOpportunity;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications;
use Illuminate\Bus\Queueable;

class MissedOpportunityNotification extends BaseHitNotification
{
    use Queueable;

    public function __construct(
        public MissedOpportunity $missedOpportunity,
        public AlertSetting $alertSetting,
        public $via = [],
        public string $timezone = 'UTC',
        public array $scans = [],
    ) {
        $this->onQueue('{high}');

        $this->missedOpportunity->loadMissing('scan', 'hotlist', 'scan.scanMetaData');
    }

    public function toMail(object $notifiable): ?Mail\MissedOpportunities\NewNotification
    {
        if ($notifiable instanceof User) {
            if (! $notifiable->isProEnabled(FeatureEnum::MISSED_OPPORTUNITY)) {
                $this->logFailure('mail', 'User is not Pro-enabled', $notifiable);

                return null;
            } else {
                $email = $notifiable->email;
            }
        } else {
            $email = $notifiable->routes['mail'][0] ?? null;
        }

        $this->sendFilamentNotification($notifiable);

        if (! $email) {
            $this->logFailure(channel: 'mail', exception: 'No email available', notifiable: $notifiable);

            return null;
        }

        try {
            $mailer = new Mail\MissedOpportunities\NewNotification(
                missedOpportunity: $this->missedOpportunity,
                alertSettingId: $this->alertSetting->id,
                alert_email: $email,
                timezone: $this->timezone,
            );

            $mailer->with($this->toArray($notifiable));
            $mailer->to($email);

            $this->logSuccess(channel: 'mail', notifiable: $notifiable, user: $email);

            return $mailer;
        } catch (\Exception $e) {
            $this->logFailure(channel: 'mail', exception: $e->getMessage(), notifiable: $notifiable);
        }

        return null;
    }

    public function sendFilamentNotification(object $notifiable): void
    {
        if ($notifiable instanceof User && $notifiable->isProEnabled(FeatureEnum::MISSED_OPPORTUNITY)) {
            try {
                Notifications\Notification::make()
                    ->title('New Missed Opportunity')
                    ->icon('heroicon-m-flag')
                    ->actions([
                        Action::make('view')
                            ->url(ListMissedOpportunities::getUrl(panel: 'app'))
                            ->button(),
                    ])
                    ->sendToDatabase($notifiable);

                $this->logSuccess(channel: 'filament', notifiable: $notifiable, user: $notifiable->email);
            } catch (\Exception $e) {
                $this->logFailure(channel: 'filament', exception: $e->getMessage(), notifiable: $notifiable);
            }
        }
    }
}
