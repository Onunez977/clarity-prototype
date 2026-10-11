<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => NotificationTypeEnum::class,
        'data' => 'array',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function alertSetting(): BelongsTo
    {
        return $this->belongsTo(AlertSetting::class);
    }

    public static function logSuccess(
        NotificationTypeEnum $type,
        ?Model $notifiable,
        ?AlertSetting $alertSetting,
        string $recipient,
        ?string $body = null,
        array $extraData = []
    ): static {
        $data = [
            'type' => $type,
            'recipient' => $recipient,
            'body' => $body,
            'status' => 'sent',
            'alert_setting_id' => $alertSetting?->id,
        ];

        if ($notifiable) {
            $data['notifiable_type'] = $notifiable->getMorphClass();
            $data['notifiable_id'] = $notifiable->id;
        }

        $data = array_merge($data, $extraData);

        return static::create($data);
    }

    public static function logFailure(
        NotificationTypeEnum $type,
        ?Model $notifiable,
        ?AlertSetting $alertSetting,
        string $recipient,
        string $errorMessage,
        ?string $body = null,
        array $extraData = []
    ): static {
        $data = [
            'type' => $type,
            'recipient' => $recipient,
            'body' => $body,
            'status' => 'failed',
            'error_message' => $errorMessage,
            'alert_setting_id' => $alertSetting?->id,
        ];

        if ($notifiable) {
            $data['notifiable_type'] = $notifiable->getMorphClass();
            $data['notifiable_id'] = $notifiable->id;
        }

        $data = array_merge($data, $extraData);

        return static::create($data);
    }
}
