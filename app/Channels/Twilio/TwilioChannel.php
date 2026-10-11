<?php

namespace App\Channels\Twilio;

use App\Enums\NotificationTypeEnum;
use App\Models\AlertSetting;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\ConfigurationException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioChannel implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public mixed $twilioClient = null,
    ) {
        $this->onQueue('{high}');

        $this->twilioClient ??= new Client(config('services.twilio.sid'), config('services.twilio.token'));
    }

    /**
     * @throws TwilioException
     * @throws ConfigurationException
     */
    public function send($notifiable, Notification $notification): void
    {
        if (config('services.twilio.sid') === null || config('services.twilio.token') === null) {
            Log::warning('Twilio is not configured');

            return;
        }

        $message = $notification->toTwilio($notifiable);

        $to = $notifiable instanceof AlertSetting
            ? $notifiable->routeNotificationFor('Twilio')
            : $notifiable->routes['sms'];

        $from = config('services.twilio.from');

        $payload = [
            'from' => $from,
            'body' => $message->content,
        ];

        //        if (\method_exists($notification, 'mediaUrl')) {
        //            if ($media = $notification->mediaUrl()) {
        //                $payload['mediaUrl'] = $media;
        //            }
        //        }

        $model = $this->getModelFromNotification($notification);
        $alertSetting = $this->getAlertSettingFromNotification($notification);

        foreach ($to as $recipient) {
            try {
                $twilioMessage = $this->twilioClient->messages->create($recipient, $payload);

                NotificationLog::logSuccess(
                    type: NotificationTypeEnum::Sms,
                    notifiable: $model,
                    alertSetting: $alertSetting,
                    recipient: $recipient,
                    body: $message->content,
                    extraData: [
                        'subject' => $message->subject,
                        'from' => $from,
                        'to' => $recipient,
                        'message_id' => $twilioMessage->sid ?? null,
                        'data' => [
                            'media_url' => $payload['mediaUrl'] ?? null,
                        ],
                    ]
                );

                if (\method_exists($notification, 'logSuccess')) {
                    $notification->logSuccess(channel: 'twilio', notifiable: $notifiable, user: $recipient);
                }
            } catch (\Exception $exception) {
                Log::error($exception->getMessage());

                NotificationLog::logFailure(
                    type: NotificationTypeEnum::Sms,
                    notifiable: $model,
                    alertSetting: $alertSetting,
                    recipient: $recipient,
                    errorMessage: $exception->getMessage(),
                    body: $message->content,
                    extraData: [
                        'subject' => $message->subject,
                        'from' => $from,
                        'to' => $recipient,
                    ]
                );

                if (\method_exists($notification, 'logFailure')) {
                    $notification->logFailure(channel: 'twilio', exception: $exception->getMessage(), notifiable: $notifiable, user: $recipient);
                }
            }
        }
    }

    private function getModelFromNotification(Notification $notification): ?object
    {
        if (\property_exists($notification, 'hit')) {
            return $notification->hit;
        }

        if (\property_exists($notification, 'missedOpportunity')) {
            return $notification->missedOpportunity;
        }

        return null;
    }

    private function getAlertSettingFromNotification(Notification $notification): ?AlertSetting
    {
        if (\property_exists($notification, 'alertSetting')) {
            return $notification->alertSetting;
        }

        return null;
    }
}
