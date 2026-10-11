<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OttoStreamFailed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $message = 'Otto could not complete that request. Please try again.',
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.error';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('otto-thread.'.$this->conversationId)];
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
        ];
    }
}
