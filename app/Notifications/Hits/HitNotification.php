<?php

namespace App\Notifications\Hits;

use App\Ai\VehicleAnalysisService;
use App\Channels\Slack\Messages\SlackMessage;
use App\Channels\Twilio\Messages\TwilioMessage;
use App\Enums\HitTypeEnum;
use App\Mail;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Laravel\Pennant\Feature;

class HitNotification extends BaseHitNotification
{
    use Queueable;

    protected ?array $vehicleAnalysis = null;

    public function __construct(
        public Hit $hit,
        public AlertSetting $alertSetting,
        public $via = [],
        public string $timezone = 'UTC',
        public array $scans = [],
    ) {
        $this->onQueue('{high}');

        $this->hit->loadMissing('scan', 'hotlist', 'scan.scanMetaData', 'kit.org');
    }

    public function toMail(object $notifiable): ?Mail\Hits\NewNotification
    {
        $email = ($notifiable instanceof User)
            ? $notifiable->email
            : ($notifiable->routes['mail'][0] ?? null);

        if (! $email) {
            $this->logFailure(channel: 'mail', exception: 'No email available', notifiable: $notifiable);

            return null;
        }

        try {
            $this->vehicleAnalysis = $this->getVehicleAnalysis();

            $mailer = new Mail\Hits\NewNotification(
                hit: $this->hit,
                alertSettingId: $this->alertSetting->id,
                alert_email: $email,
                timezone: $this->timezone,
                scans: $this->scans,
            );

            $data = $this->toArray($notifiable);
            $data['vehicle_analysis'] = $this->vehicleAnalysis;

            $mailer->with($data);
            $mailer->to($email);

            $this->logSuccess(channel: 'mail', notifiable: $notifiable, user: $email, vehicleAnalysis: $this->vehicleAnalysis);

            return $mailer;
        } catch (\Exception $e) {
            $this->logFailure(channel: 'mail', exception: $e->getMessage(), notifiable: $notifiable);
        }

        return null;
    }

    protected function getVehicleAnalysis(): ?array
    {
        $user = $this->alertSetting->alertable;

        if (! $user instanceof User || ! Feature::for($user)->active('ai-notifications')) {
            return null;
        }

        return app(VehicleAnalysisService::class)->analyze($this->hit, $this->timezone);
    }

    public function toSlack($notifiable): SlackMessage
    {
        if (\in_array($this->hit->hit_type, [HitTypeEnum::Historical->value, HitTypeEnum::Staging_Historical->value]) && \count($this->scans) > 1) {
            return (new SlackMessage)->multiScanContent(
                data: $this->toArray($notifiable),
                scans: collect($this->scans),
                timezone: $this->timezone,
            );
        }

        return parent::toSlack($notifiable);
    }

    public function toTwilio($notifiable): TwilioMessage
    {
        if (\in_array($this->hit->hit_type, [HitTypeEnum::Historical->value, HitTypeEnum::Staging_Historical->value]) && \count($this->scans) > 1) {
            return (new TwilioMessage)->multiScanContent(
                data: $this->toArray($notifiable),
                scans: collect($this->scans),
                timezone: $this->timezone,
            );
        }

        return parent::toTwilio($notifiable);
    }
}
