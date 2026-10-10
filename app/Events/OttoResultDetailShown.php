<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OttoResultDetailShown implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $resultType,
        public int $resultId,
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.detail';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('otto-thread.'.$this->conversationId)];
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'result_type' => $this->resultType,
            'result_id' => $this->resultId,
        ];
    }
}
