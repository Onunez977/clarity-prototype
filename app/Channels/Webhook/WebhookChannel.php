<?php

namespace App\Channels\Webhook;

use App\Enums\NotificationTypeEnum;
use App\Models\AlertSetting;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookChannel implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('{high}');
    }

    public function send($notifiable, Notification $notification): void
    {
        $payload = $notification->toWebhook($notifiable);

        $urls = $notifiable instanceof AlertSetting
            ? $notifiable->routeNotificationFor('webhook')
            : ($notifiable->routes['webhook'] ?? []);

        $model = $this->getModelFromNotification($notification);
        $alertSetting = $this->getAlertSettingFromNotification($notification);
        $secret = $alertSetting?->webhook_secret ?? '';

        foreach ($urls as $url) {
            try {
                Http::retry(3, 500)
                    ->connectTimeout(2)
                    ->timeout(5)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-Webhook-Secret' => $secret,
                    ])
                    ->post($url, $payload)
                    ->throw();

                NotificationLog::logSuccess(
                    type: NotificationTypeEnum::Webhook,
                    notifiable: $model,
                    alertSetting: $alertSetting,
                    recipient: $url,
                    body: json_encode($payload),
                    extraData: ['to' => $url],
                );

                if (\method_exists($notification, 'logSuccess')) {
                    $notification->logSuccess(channel: 'webhook', notifiable: $notifiable, user: $url);
                }
            } catch (\Exception $exception) {
                Log::channel('webhook')->error($exception->getMessage());

                NotificationLog::logFailure(
                    type: NotificationTypeEnum::Webhook,
                    notifiable: $model,
                    alertSetting: $alertSetting,
                    recipient: $url,
                    errorMessage: $exception->getMessage(),
                    body: json_encode($payload),
                    extraData: ['to' => $url],
                );

                if (\method_exists($notification, 'logFailure')) {
                    $notification->logFailure(channel: 'webhook', exception: $exception->getMessage(), notifiable: $notifiable, user: $url);
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
