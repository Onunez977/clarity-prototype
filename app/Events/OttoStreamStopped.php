<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OttoStreamStopped implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.stopped';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('otto-thread.'.$this->conversationId)];
    }
}
