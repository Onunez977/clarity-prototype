<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OttoInvestigationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public string $conversationId,
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.investigation.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('otto.'.$this->userId.'.'.$this->conversationId),
        ];
    }
}
