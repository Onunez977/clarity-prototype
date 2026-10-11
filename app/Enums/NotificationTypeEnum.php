<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationTypeEnum: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Slack = 'slack';
    case Webhook = 'webhook';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
