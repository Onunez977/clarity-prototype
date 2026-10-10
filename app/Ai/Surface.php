<?php

namespace App\Ai;

enum Surface: string
{
    case Chat = 'chat';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Otto chat',
        };
    }
}
