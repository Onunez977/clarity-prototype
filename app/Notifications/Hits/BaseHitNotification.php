<?php

namespace App\Notifications\Hits;

use App\Channels\Slack\Messages\SlackMessage;
use App\Channels\Twilio\Messages\TwilioMessage;
use App\Enums\HitTypeEnum;
use App\Facades\Analytics;
use App\Models\Hit;
use App\Models\MissedOpportunity;
use App\Models\User;
use App\Services\HitPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BaseHitNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return $this->via ?? [];
    }

    public function mediaUrl(): array
    {
        if ($guid = $this->getModel()?->scan?->guid) {
            $urls = [
                scan_img($guid, 'c'),
                scan_img($guid, 'p'),
            ];

            // Check if the URLs are valid
            return \array_filter($urls, fn ($url) => Http::get($url)->successful());
        }

        return [];
    }

    public function toTwilio($notifiable): TwilioMessage
    {
        return (new TwilioMessage)->content($this->toArray($notifiable));
    }

    public function toSlack($notifiable): SlackMessage
    {
        return (new SlackMessage)->content($this->toArray($notifiable));
    }

    public function logSuccess($channel, $notifiable, $user = null, ?array $vehicleAnalysis = null): void
    {
        if (! $user) {
            $user = ($notifiable instanceof User)
                ? $notifiable->email
                : Arr::first($notifiable->routeNotificationFor($channel));
        }

        $model = $this->getModel();

        $type = $model instanceof Hit
            ? 'hit'
            : 'missed-opportunity';

        $properties = [
            'to' => $user,
            'channel' => $channel,
        ];

        if ($vehicleAnalysis) {
            $properties['vehicle_analysis'] = $vehicleAnalysis;
        }

        activity($type.'-notification')
            ->performedOn($model)
            ->withProperties($properties)
            ->log('Notification Sent');

        Log::channel('hits')->info(Str::headline($type).' Notification sent', [
            'to' => $user,
            'id' => $model->id,
            'channel' => $channel,
        ]);

        $action = match ($channel) {
            'mail' => 'email',
            'twilio' => 'sms',
            'slack' => 'slack',
            default => null,
        };

        if ($action) {
            Analytics::track('notification-sent', $this->alertSetting->alertable ?? null, [
                'action' => $action,
                'hit_id' => $model->id,
                'hit_type' => $model->hit_type,
                'hotlist_id' => $model->hotlist_id,
            ]);
        }
    }

    public function logFailure($channel, $exception, $notifiable, $user = null): void
    {
        if (! $user) {
            $user = ($notifiable instanceof User)
                ? $notifiable->email
                : Arr::first($notifiable->routeNotificationFor($channel));
        }

        $model = $this->getModel();

        $type = $model instanceof Hit
            ? 'hit'
            : 'missed-opportunity';

        activity($type.'-notification')
            ->performedOn($model)
            ->withProperties([
                'to' => $user,
                'channel' => $channel,
                'exception' => $exception,
            ])
            ->log('Notification Failed');

        Log::channel('hits')->error(Str::headline($type).' Notification failed', [
            'to' => $user,
            'id' => $model->id,
            'channel' => $channel,
            'exception' => $exception,
        ]);
    }

    public function toArray($notifiable): array
    {
        $model = $this->getModel();

        return [
            'id' => $model->id,
            'time' => $model->scan?->scannedAt?->format(config('app.date_format')) ?? $model->hitDate->format(config('app.date_format')),
            'type' => HitTypeEnum::tryFrom($model->hit_type)->name,
            'created_at' => $model->created_at->timezone($notifiable?->timezone ?? 'UTC')->format(config('app.date_format')),
            'hotlist' => [
                'id' => $model->hotlist?->id,
                'name' => $model->hotlist?->integration?->name ?? 'N/A',
                'plate' => $model->hotlist?->plate,
                'state' => $model->hotlist?->us_state,
                'vin' => $model->hotlist?->vin,
                'year' => $model->hotlist?->vehicle_year,
                'make' => $model->hotlist?->vehicle_make,
                'model' => $model->hotlist?->vehicle_model,
                'client_name' => $model->hotlist?->client?->name ?? 'N/A',
                'remote_order_id' => $model->hotlist?->remote_order_id,
            ],
            'scan' => [
                'id' => $model->scan?->id,
                'lat' => $model->scan?->lat,
                'lng' => $model->scan?->lng,
                'address' => $model->scan?->address,
                'image_url_p' => $model->scan?->plateImageUrl,
                'image_url_c' => $model->scan?->carImageUrl,
                'plate' => $model->scan?->ocr,
                'state' => $model->scan?->us_state,
                'meta' => $model->scan?->scanMetaData?->meta->toFormattedArray(withTitles: true) ?? [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhook(object $notifiable): array
    {
        $model = $this->getModel();

        return \array_merge(
            ['hit_type' => HitTypeEnum::tryFrom($model->hit_type)?->name],
            HitPayload::build($model),
        );
    }

    private function getModel(): Hit|MissedOpportunity
    {
        if (\property_exists($this, 'hit')) {
            return $this->hit;
        } elseif (\property_exists($this, 'missedOpportunity')) {
            return $this->missedOpportunity;
        } else {
            throw new \Exception('No property is not set on Notification');
        }
    }
}
