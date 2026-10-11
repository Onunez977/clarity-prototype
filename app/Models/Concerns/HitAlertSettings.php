<?php

namespace App\Models\Concerns;

use App\Channels\Slack\SlackChannel;
use App\Channels\Twilio\TwilioChannel;
use App\Channels\Webhook\WebhookChannel;
use App\Dto\AnalyticsDataDto;
use App\Dto\HitNotificationData;
use App\Dto\SQSData;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Jobs\TrackAnalytics;
use App\Models\AlertSetting;
use App\Models\User;
use App\Notifications\Hits\HitNotification;
use App\Notifications\Hits\MissedOpportunityNotification;
use App\Pipelines;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Pipeline;
use Laravel\Pennant\Feature;

trait HitAlertSettings
{
    protected function kitAlertSettings(): Collection
    {
        return cache()->flexible(
            'alert_settings:v'.AlertSetting::subscriberCacheVersion($this->kit->org_id).':t:'.$this->hit_type.':k:'.$this->kit_id,
            [now()->addMinutes(10), now()->addMinutes(30)],
            fn () => AlertSetting::query()
                ->whereIn('alertable_id', User::query()->where('org_id', $this->kit->org_id)->pluck('id'))
                ->where('alertable_type', 'user')
                ->whereJsonContains('settings->types', $this->hit_type)
                ->where(function ($query) {
                    foreach (['sms', 'emails', 'slack'] as $channel) {
                        $query->orWhereRaw('jsonb_array_length('.AlertSetting::normalizedListSql("notifications,{$channel}").') > 0');
                    }

                    $query->orWhere('settings->webhook_url', '!=', '');
                })
                ->get()
        );
    }

    public function getSubscribers(?SQSData $sqsData = null): Collection
    {
        Log::shareContext(['hit_id' => $this->id]);
        Log::channel('hits')->info('Getting Subscribers');

        // If the hit does not have a kit, we can't do anything
        if (empty($this->kit)) {
            Log::channel('hits')->info('No Kit Found');

            return collect();
        }

        return $this->kitAlertSettings()
            ->filter(function ($alertSetting) use ($sqsData) {
                Log::shareContext(['alert_setting_id' => $alertSetting->id]);

                $filtered = Pipeline::send(
                    new HitNotificationData(alertSetting: $alertSetting, hit: $this, sqsData: $sqsData)
                )
                    ->through([
                        Pipelines\HitNotifications\ProFilterPipeline::class,
                        Pipelines\HitNotifications\ClientFilterPipeline::class,
                        Pipelines\HitNotifications\HotlistStatusFilterPipeline::class,
                    ])
                    ->thenReturn();

                if ($filtered instanceof HitNotificationData) {
                    Log::channel('hits')->info('Subscribers found');

                    // Move to Sending Notifications
                    return true;
                }

                return false;
            });
    }

    public function notifySubscribers(?SQSData $sqsData = null, array $scans = []): void
    {
        Log::shareContext(['hit_id' => $this->id]);

        // If the hit does not have a kit, we can't do anything
        if (empty($this->kit)) {
            Log::channel('hits')->info('No Kit Found');

            return;
        }

        // Prevent duplicate notifications for the same Hit
        $cacheKey = 'hit-notified:'.$this->id.':hotlist:'.$this->hotlist_id;
        if (cache()->has($cacheKey)) {
            Log::channel('hits')->info('Duplicate notification prevented', ['hit_id' => $this->id, 'hotlist_id' => $this->hotlist_id]);

            if (config('database.track_analytics') && config('database.use_clickhouse')) {
                dispatch(new TrackAnalytics(
                    feature: 'notification-duplicate-skipped',
                    userId: null,
                    orgId: $this->kit->org_id,
                    data: AnalyticsDataDto::fromArray([
                        'action' => 'skipped',
                        'hit_id' => $this->id,
                        'hit_type' => $this->hit_type,
                        'hotlist_id' => $this->hotlist_id,
                    ]),
                ));
            }

            return;
        }

        cache()->put($cacheKey, true, now()->addMinutes(3));

        $this->getSubscribers($sqsData)
            ->each(function (AlertSetting $alertSetting) use ($scans) {
                $alertSetting
                    ->routeNotificationFor('sms')
                    ->each(fn ($phone) => $this->sendNotification('sms', $phone, $alertSetting, $scans));

                $alertSetting
                    ->routeNotificationFor('mail')
                    ->each(fn ($email) => $this->sendNotification('mail', $email, $alertSetting, $scans));

                $alertSetting
                    ->routeNotificationFor('slack')
                    ->each(fn ($channel) => $this->sendNotification('slack', $channel, $alertSetting, $scans));

                if ($alertSetting->alertable instanceof User
                    && Feature::for($alertSetting->alertable)->active(FeatureEnum::HIT_WEBHOOK->slug())) {
                    $alertSetting
                        ->routeNotificationFor('webhook')
                        ->each(fn ($url) => $this->sendNotification('webhook', $url, $alertSetting, $scans));
                }
            });
    }

    private function sendNotification(string $type, mixed $receiver, AlertSetting $alertSetting, array $scans = []): void
    {
        $typeClass = $this->hit_type === HitTypeEnum::Missed_Opportunity->value
            ? MissedOpportunityNotification::class
            : HitNotification::class;

        $channel = $type;
        if ($channel === 'sms') {
            $channel = TwilioChannel::class;
        } elseif ($channel === 'slack') {
            $channel = SlackChannel::class;
        } elseif ($channel === 'webhook') {
            $channel = WebhookChannel::class;
        }

        Notification::route(
            $type,
            \is_array($receiver) ? $receiver : [$receiver]
        )->notify(new $typeClass(
            $this,
            alertSetting: $alertSetting,
            via: [$channel],
            timezone: $alertSetting->alertable->timezone ?? 'UTC',
            scans: $scans,
        ));

        Log::channel('hits')->info('Sending '.$type.' notification to '.$receiver);
    }
}
