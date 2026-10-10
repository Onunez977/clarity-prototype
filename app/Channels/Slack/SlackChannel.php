<?php

namespace App\Channels\Slack;

use App\Enums\NotificationTypeEnum;
use App\Models\AlertSetting;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Spatie\SlackAlerts\Facades\SlackAlert;

class SlackChannel implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('{high}');
    }

    public function send($notifiable, Notification $notification): void
    {
        $message = $notification->toSlack($notifiable)?->content ?? [];

        $webhooks = $notifiable instanceof AlertSetting
            ? $notifiable->routeNotificationFor('Slack')
            : $notifiable->routes['slack'];

        if (\method_exists($notification, 'mediaUrl')) {
            if ($media = $notification->mediaUrl()) {
                foreach ($media as $image) {
                    $message[] = [
                        'type' => 'image',
                        'title' => [
                            'type' => 'plain_text',
                            'text' => 'Hit Image(s)',
                        ],
                        'image_url' => $image,
                        'alt_text' => 'Hit Image(s)',
                    ];
                }
            }
        }

        $message[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => 'Powered by Insight LPR + Clarity',
                ],
            ],
        ];

        $model = $this->getModelFromNotification($notification);
        $alertSetting = $this->getAlertSettingFromNotification($notification);

        foreach ($webhooks as $webhook) {
            try {
                SlackAlert::to($webhook)
                    ->withUsername('Insight LPR + Clarity')
                    ->withIconURL(config('app.url').'/img/cropped-Favicon-180x180.png')
                    ->blocks($message);

                NotificationLog::logSuccess(
                    type: NotificationTypeEnum::Slack,
                    notifiable: $model,
                    alertSetting: $alertSetting,
                    recipient: $webhook,
                    body: json_encode($message),
                    extraData: [
                        'from' => 'Insight LPR + Clarity',
                        'to' => $webhook,
                    ]
                );

                if (\method_exists($notification, 'logSuccess')) {
                    $notification->logSuccess(channel: 'slack', notifiable: $notifiable, user: $webhook);
                }
            } catch (\Exception $exception) {
                Log::error($exception->getMessage());

                NotificationLog::logFailure(
                    type: NotificationTypeEnum::Slack,
                    notifiable: $model,
                    alertSetting: $alertSetting,
                    recipient: $webhook,
                    errorMessage: $exception->getMessage(),
                    body: json_encode($message),
                    extraData: [
                        'from' => 'Insight LPR + Clarity',
                        'to' => $webhook,
                    ]
                );

                if (\method_exists($notification, 'logFailure')) {
                    $notification->logFailure(channel: 'slack', exception: $exception->getMessage(), notifiable: $notifiable, user: $webhook);
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
